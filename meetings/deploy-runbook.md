# board 배포 런북 (car-erp 옆 Lightsail vhost)

> 작성 2026-06-15. car-erp 배포(GitHub Actions SSH + nginx + php8.4-fpm) 미러.
> 배포 대상 = car-erp 와 **같은 Lightsail 인스턴스**에 board vhost 추가. 실행 = Jin 직접.

---

## A. 배포 전 완료 (코드/설정 — 이미 됨 ✅)

| 항목 | 상태 |
|---|---|
| `.github/workflows/deploy.yml` (master push → SSH 배포) | ✅ |
| `db:backup` 커맨드 + 야간 03:00 스케줄 | ✅ |
| 환율 매시 갱신 스케줄 + lazy 갱신 | ✅ |
| `config/services.php` car_erp·respond_io 블록 | ✅ |
| `config/filesystems.php` db_backup_disk | ✅ |
| `.env.example` 운영 키 전부 문서화(+APP_KEY 경고) | ✅ |
| 마이그레이션 fresh 정합성 (테스트 27/27 = sqlite fresh 통과) | ✅ |
| DummyBoardSeeder 는 기본 시드(DatabaseSeeder)에 미포함(운영 안전) | ✅ |

→ 남은 건 **서버 작업뿐**(아래 B). 코드는 dev→master 머지로 자동 반영.

---

## B. 서버 1회 셋업 (Jin, Lightsail SSH)

### B-1. DB 생성 (MySQL, board 전용 user — car_erp 접근 0)
```sql
CREATE DATABASE board CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'board_user'@'localhost' IDENTIFIED BY '강한비밀번호';
GRANT ALL PRIVILEGES ON board.* TO 'board_user'@'localhost';   -- board DB 에만
FLUSH PRIVILEGES;
```

### B-2. 코드 배치 (예: /var/www/board)
```bash
cd /var/www
git clone -b master https://github.com/wlsdud10075-JIN/board.git board
cd board
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
npm ci && npm run build
```

### B-3. .env 작성 (가장 중요)
```bash
cp .env.example .env
nano .env   # DB_PASSWORD, AWS_*, APP_URL=https://board.heysellcar.com, APP_ENV=production, APP_DEBUG=false,
            #  BOARD_PHOTO_DISK=s3, DB_BACKUP_DISK=s3, AWS_BUCKET 등 채움
php artisan key:generate          # ⚠️ APP_KEY 1회 생성 → 즉시 백업(payee 암호화 키, 분실=복호화 불가)
php artisan migrate --force
php artisan db:seed --class=DatabaseSeeder   # 운영 계정 시드(1회). ⚠️ DummyBoardSeeder 절대 금지
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

### B-3b. php.ini 업로드 한도 (검차 영상 대비)
```ini
# /etc/php/8.4/fpm/php.ini  (영상 업로드용 — Livewire 한도 200MB·nginx 100M 와 맞춤)
upload_max_filesize = 100M
post_max_size = 100M
```
```bash
sudo systemctl reload php8.4-fpm
```

### B-4. nginx vhost (`/etc/nginx/sites-available/board`)
```nginx
server {
    listen 80;
    server_name board.heysellcar.com;
    root /var/www/board/public;
    index index.php;
    client_max_body_size 100M;   # 검차 영상 업로드 대비

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;   # car-erp 와 동일 소켓
    }
    location ~ /\.(?!well-known).* { deny all; }
}
```
```bash
sudo ln -s /etc/nginx/sites-available/board /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### B-5. DNS + HTTPS
- 도메인 등록기관에서 **A 레코드**: `board.heysellcar.com → Lightsail 고정 IP`
- ```bash
  sudo certbot --nginx -d board.heysellcar.com   # HTTPS 자동 + 자동갱신
  ```

### B-6. 큐 워커 (Supervisor — `/etc/supervisor/conf.d/board-worker.conf`)
```ini
[program:board-worker]
command=php /var/www/board/artisan queue:work --sleep=3 --tries=3 --max-time=3600
directory=/var/www/board
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/board/storage/logs/worker.log
stopwaitsecs=3600
```
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start board-worker:*
```

### B-7. 스케줄러 cron (환율 매시 + 백업 03:00 자동)
```bash
sudo crontab -u www-data -e
# 추가:
* * * * * cd /var/www/board && php artisan schedule:run >> /dev/null 2>&1
```

### B-8. GitHub Actions 시크릿 (repo → Settings → Environments → Production)
| 시크릿 | 값 |
|---|---|
| DEPLOY_HOST | Lightsail IP/호스트 |
| DEPLOY_USER | ubuntu (또는 배포 유저) |
| DEPLOY_SSH_KEY | 배포용 개인키 |
| DEPLOY_PORT | 22 |
| DEPLOY_PATH | /var/www/board |

---

## C. 이후 배포 (자동)
```bash
# 로컬에서 (CLAUDE.md Git 규칙):
git checkout master
git merge --no-commit --no-ff dev
git ls-files '*.md' | xargs -r git rm -fq    # .md 제외(운영 트리에 내부문서 미노출)
git commit -m "merge dev → master (.md 제외)"
git push origin master                        # → Actions deploy.yml 자동 실행
git checkout dev
```
→ 서버에서 git pull·build·migrate·cache·fpm reload·queue:restart 자동.

## D. 배포 후 검증
- [ ] `https://board.heysellcar.com` 접속 → 로그인(admin@board.test) 됨
- [ ] 매입예정 환율 배너 **LIVE** (lazy/스케줄 갱신)
- [ ] 검차 사진 업로드 → S3 저장 확인
- [ ] `php artisan db:backup` 수동 1회 → storage/backups/db + (s3) 확인
- [ ] 큐: `sudo supervisorctl status board-worker:*` running

## E. 주의
- **APP_KEY** = board 전용(car-erp 와 다름). 생성 후 변경 금지 + 백업(payee_account 암호화).
- **DummyBoardSeeder** 는 운영 절대 금지(더미 50건 생성). 운영 시드는 `DatabaseSeeder` 만.
- master 트리엔 **.md 0개**(merge 시 .md 제외) — 운영에 내부문서·식별값 미노출.
- 연동 A/B 는 배포 후 별도(대표 승인·respond.io 설정) → 코드는 master 머지로 반영.
