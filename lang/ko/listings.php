<?php

return [
    // 헤더
    'heading' => '매입예정 (영업)',
    'own_only_note' => '🔒 본인(:name) 리스트만 표시 — 서버/DB 레벨 격리',

    // 환율
    'rate' => [
        'label' => '💱 적용 환율',
        'live' => 'LIVE',
        'temp' => '임시',
        'refresh_title' => '환율 갱신',
        'usd_line' => 'USD 1 = :amount원',
        'eur_line' => 'EUR 1 = :amount원',
        'as_of' => ':time 기준',
        'refreshed' => '환율을 갱신했습니다.',
    ],

    // 시간잠금 안내
    'timegate' => [
        'auction_closed' => '🔨 경매 등록 마감됨 (:time 이후 · 관리자 해제 필요)',
        'auction_open' => '🔨 경매 등록 가능 (:time 마감)',
        'encar_always' => '· 🛒 엔카는 상시 등록',
    ],

    // 승격 대기
    'promo' => [
        'heading' => '📥 승격 대기',
        'count' => ':count명',
        'intro' => '바이어가 채팅에서 board 처리를 요청했습니다. “승격”을 누르면 컨택트가 자동 연결됩니다 — 링크와 차량번호만 입력하세요.',
        'contact_fallback' => '컨택트 :id',
        'contact_meta' => '컨택트 :id',
        'assignee' => '담당: :name',
        'unassigned' => '미배정',
        'promote' => '승격',
        'dismiss' => '무시',
        'dismiss_confirm' => '이 승격 대기를 무시하시겠습니까?',
        'promoted_flash' => '[:label] 바이어 연결됨 — 링크와 차량번호만 입력하면 됩니다.',
    ],

    // 리스트 헤더
    'list' => [
        'heading' => '내 매입예정 리스트',
        'count' => ':count건',
        'add' => '+ 매입예정 추가',
        'empty' => '매입예정이 없습니다. “+ 매입예정 추가”로 등록하세요.',
        'row_hint' => '💡 행을 클릭하면 내용을 보고 수정할 수 있습니다 (시간잠금된 경매 차량 제외).',
    ],

    // 표 헤더
    'table' => [
        'vehicle' => '차량',
        'source' => '출처',
        'total' => '최종금액',
        'inspection_note' => '추가검사사항',
        'buyer' => '바이어',
        'status' => '상태',
    ],

    // 추가 폼
    'add_form' => [
        'origin_label' => '출처 (유입 카테고리)',
        'method_prefix' => '💡 매입방법: ',
        'method_auction' => '경매 (시간잠금·낙찰/유찰)',
        'method_encar' => '엔카 즉시구매 (구매대기/확정)',
        'method_suffix' => ' — 카테고리에서 자동 결정',
        'vehicle_number' => '차량번호',
        'vehicle_number_ph' => '12가3456',
        'owner' => '소유자',
        'owner_hint' => '(차주명)',
        'owner_ph' => '등록 소유자명',
        'car_cost' => '차값',
        'car_cost_ph' => '13000000',
        'discount_rate' => '할인율 (%)',
        'discount_rate_ph' => '0',
        'region' => '지역',
        'region_ph' => '수원 입력 → 자동완성',
        'c_no' => '매물번호',
        'c_no_hint' => '(c_no)',
        'c_no_ph' => '링크 추출 시 자동',
        'auction_venue' => '경매장',
        'auction_venue_ph' => '롯데 / 현대 글로비스',
        'lot_number' => '출품번호',
        'lot_number_ph' => 'A-1024',
        'note' => '<b>차량번호</b> 필수. 금액은 선택 입력이며 현지 차상태 확인 후 조정될 수 있습니다.',
        'saved_flash' => '매입예정이 등록되었습니다.',
        'dup_error' => '이미 등록된 차량번호입니다 (#:id).',
        'auction_locked_error' => '경매 차량 등록은 :time 에 마감되었습니다. 관리자 해제가 필요합니다.',
        'attr_vehicle_number' => '차량번호',
        'attr_vin' => '차대번호(VIN)',
    ],

    // 링크 추출 / 매물 표시가
    'links' => [
        'encar_label' => '🔗 엔카 링크',
        'encar_hint' => '(차량번호·차값·지역·VIN 자동)',
        'encar_ph' => 'https://fem.encar.com/cars/detail/42176484',
        'ssancar_label' => '🔗 ssancar 링크',
        'ssancar_hint' => '(차량번호·VIN · 검차매물은 엔카가격까지)',
        'ssancar_ph' => 'https://www.ssancar.com/...?c_no= / ?wr_id=',
        'extract' => '추출',
        'extracted' => '추출됨:',
        'parse_error' => '링크에서 식별값을 찾지 못했습니다. (직접 입력 가능)',
        'price_label' => '매물 표시가 (= 차값)',
        'price_hint' => '(링크 추출 시 자동 · 통화 선택)',
        'price_ph' => '링크 추출 시 자동 · 직접 입력 가능',
        'price_options_prefix' => '💱 링크 표시가: ',
        'price_options_suffix' => '— 버튼으로 통화 선택(금액 자동 변경)',
        'price_help' => '💡 엔카=원화 / ssancar=3통화 자동. 통화 버튼으로 선택한 통화 금액이 아래 ‘차값’에 그대로 들어갑니다(외화 그대로 · 수정 가능). 환율 환산은 금액산정에서만.',
        'contact_label' => 'respond.io 컨택트 ID',
        'contact_hint' => '(선택 · 바이어 식별 · 자동회신 매칭키)',
        'contact_ph' => 'respond.io 바이어 컨택트 ID (예: 469733036)',
        'enrich_category' => '[:cat] 추출: ',
        'enrich_name' => ' · 차종: :name',
        'enrich_auto' => ' · 자동채움: :fields',
        'enrich_suffix' => ' — 확인 후 저장하세요.',
        'fill_vehicle_number' => '차량번호',
        'fill_price' => '매물표시가(:currencies)',
        'fill_car_cost' => '차값(:currency)',
        'fill_region' => '지역',
        'fill_vin' => 'VIN',
    ],

    // 금액 산정
    'pricing' => [
        'heading' => '금액 산정',
        'sales_fee' => '＋ 매도비 (고정)',
        'car_price' => '차량금액 (Car Price)',
        'car_price_short' => '차량금액',
        'shipping_label' => '배송금액 (USD 고정)',
        'shipping_prefix' => '배송 ',
        'total' => '최종금액 (Total)',
        'total_short' => '최종금액',
    ],

    // 입금정보
    'payee' => [
        'label' => '입금정보',
        'hint' => '(선택 · 정산계좌)',
        'bank_ph' => '은행',
        'name_ph' => '예금주',
        'account_ph' => '계좌번호',
        'help' => '💡 지금 알면 미리 입력 → 구매단계 자동 표시. 비워두면 구매담당자가 입력. (은행 선택 시 계좌 자동 하이픈 · 계좌번호 암호화)',
    ],

    'selling_fee_payee' => [
        'label' => '매도비 계좌',
        'hint' => '(선택 · 판매자와 다른 대상)',
        'help' => '💡 매도비(매도비 고정액)를 받는 별도 계좌 — 매입가 계좌와 다른 대상일 때 입력. (은행 선택 시 계좌 자동 하이픈 · 암호화)',
    ],

    // 차량 첨부
    'attach' => [
        'add_label' => '차량 첨부파일',
        'add_hint' => '(사진·서류·엑셀 등 · 최대 :max건 · 낙찰 시 car-erp 자동등록)',
        'dropzone' => '📎 파일 선택 (여러 개 가능 · 실행파일 제외 모두)',
        'uploading' => '업로드 중…',
        'selected' => ':count개 선택됨 — 저장 시 반영',
        'help' => '💡 영업이 받은 차량 사진·서류를 올리면 낙찰 후 car-erp 에 자동 등록 → 관리가 확인·보완. (이미지=사진/그 외=서류 자동분류, 실행파일만 불가)',
        'exec_error' => '실행파일(.exe 등)은 올릴 수 없습니다: :name',
        'max_error' => '첨부파일은 최대 :max건까지입니다. (현재 :existing건)',
        // 드로어
        'drawer_label' => '차량 첨부',
        'drawer_hint' => '(사진·서류 · 최대 :max건)',
        'drawer_empty' => '아직 첨부가 없습니다.',
        'drawer_add' => '📎 파일 추가 (사진·서류·엑셀 등 · 실행파일 제외)',
        'delete_confirm' => '이 첨부를 삭제하시겠습니까?',
    ],

    // 편집 드로어
    'drawer' => [
        'title' => ':number · 매입예정 수정',
        'money_moved' => '금액·입금계좌·차량첨부는 이후 단계(견적·전달 / 구매확정)에서 입력합니다.',
        'summary_locked' => '식별값(차량번호·VIN)·출처는 수정 불가',
        'method_auction' => '경매',
        'method_encar' => '엔카 즉시구매',
        'locked_notice' => '🔒 시간잠금된 경매 차량입니다. 수정은 관리자에게 문의하세요.',
        'locked_error' => '시간잠금된 경매 차량은 수정할 수 없습니다. (관리자 문의)',
        'encar_url' => '엔카 매물 URL',
        'encar_url_ph' => 'https://encar.com/...',
        'c_no_ph' => '예: 6797296',
        'contact_label' => 'respond.io 컨택트 ID',
        'contact_hint' => '(바이어 식별 · 자동회신 매칭키)',
        'contact_ph' => 'respond.io 바이어 컨택트 ID',
        'origin_prefix' => '유입:',
        'local_total' => '현지 최종금액',
        'local_total_pending' => '— (현지확인 후)',
        'status' => '상태',
        'buyer' => '바이어',
        'buyer_name' => '바이어명',
        'updated_flash' => ':number 수정되었습니다.',
    ],
];
