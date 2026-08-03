<?php

namespace Tests\Feature;

use App\Models\BoardAuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\OllamaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 사내 업무 도우미(A: 업무가이드 RAG) — 권한 게이트 · 색인 · 감사기록.
 * Ollama 는 fake 로 바인딩해 HTTP 를 발생시키지 않는다.
 */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // 기본 = 인프라·기능설정 둘 다 on (개별 테스트에서 필요하면 끈다)
        config(['assistant.enabled' => true]);
        Setting::updateOrCreate(['key' => 'assistant_enabled'], ['value' => '1', 'type' => 'boolean']);
    }

    private function mkUser(string $role, string $permission = 'user'): User
    {
        return User::create([
            'name' => $role,
            'email' => $role.(++$this->seq).'@t.test',
            'password' => 'password',
            'role' => $role,
            'permission' => $permission,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** 색인 파일을 임시로 만들고 경로를 config 에 물린다. */
    private function fakeIndex(array $docs): string
    {
        $path = storage_path('framework/testing/index-board-test.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode($docs));
        config(['assistant.index_path' => $path]);

        return $path;
    }

    private function fakeOllama(array $embedding = [1, 0], string $answer = '테스트 답변'): void
    {
        $this->app->bind(OllamaClient::class, fn () => new class($embedding, $answer) extends OllamaClient
        {
            public function __construct(private array $emb, private string $ans)
            {
                parent::__construct('http://fake');
            }

            public function embed(string $model, string $text): array
            {
                return $this->emb;
            }

            public function chat(string $model, string $system, string $user): string
            {
                return $this->ans;
            }
        });
    }

    public function test_영업_관리_super_만_챗봇을_쓸_수_있다(): void
    {
        $this->assertTrue($this->mkUser('sales')->canUseAssistant());
        $this->assertTrue($this->mkUser('manager')->canUseAssistant());
        $this->assertTrue($this->mkUser('inspection', 'super')->canUseAssistant());   // super 는 role 무관

        $this->assertFalse($this->mkUser('inspection')->canUseAssistant());
        $this->assertFalse($this->mkUser('auction')->canUseAssistant());
    }

    public function test_서버설정이나_기능설정이_꺼져있으면_못_쓴다(): void
    {
        $sales = $this->mkUser('sales');

        config(['assistant.enabled' => false]);
        $this->assertFalse($sales->canUseAssistant());

        config(['assistant.enabled' => true]);
        Setting::updateOrCreate(['key' => 'assistant_enabled'], ['value' => '0', 'type' => 'boolean']);
        $this->assertFalse($sales->fresh()->canUseAssistant());
    }

    public function test_비활성_계정은_못_쓴다(): void
    {
        $sales = $this->mkUser('sales');
        $sales->update(['is_active' => false]);

        $this->assertFalse($sales->fresh()->canUseAssistant());
    }

    public function test_색인이_없으면_안내문을_답한다(): void
    {
        config(['assistant.index_path' => '']);
        $this->fakeOllama();

        $res = app(AssistantService::class)->ask('매입예정 어떻게 등록해?', $this->mkUser('sales'));

        $this->assertSame(__('assistant.no_index'), $res['answer']);
    }

    public function test_가장_가까운_청크를_근거로_답하고_출처를_돌려준다(): void
    {
        $this->fakeIndex([
            ['source' => '매입보드 › 영업 › 등록', 'text' => '매입예정은 /listings 에서 등록합니다.', 'embedding' => [1, 0]],
            ['source' => '매입보드 › 검차', 'text' => '검차는 ssancar 에 올립니다.', 'embedding' => [0, 1]],
        ]);
        $this->fakeOllama([1, 0], '매입예정 화면에서 등록합니다.');
        config(['assistant.rag_topk' => 1]);

        $res = app(AssistantService::class)->ask('매입예정 어떻게 등록해?', $this->mkUser('sales'));

        $this->assertSame('매입예정 화면에서 등록합니다.', $res['answer']);
        $this->assertSame('매입보드 › 영업 › 등록', $res['sources'][0]['title']);
    }

    public function test_질의는_감사로그에_남되_변경이력_목록엔_안_섞인다(): void
    {
        $this->fakeIndex([['source' => 's', 'text' => 't', 'embedding' => [1, 0]]]);
        $this->fakeOllama();
        $sales = $this->mkUser('sales');

        app(AssistantService::class)->ask('전달 대기가 뭐야?', $sales);

        $log = BoardAuditLog::where('action', 'assistant_query')->sole();
        $this->assertSame($sales->id, $log->user_id);
        $this->assertNull($log->purchase_listing_id);
        $this->assertSame('전달 대기가 뭐야?', $log->new_value);

        // /audit 변경이력 표에는 안 나온다(매물 변경이 아니라 표 형식이 안 맞고 실제 이력을 밀어낸다)
        Volt::actingAs($this->mkUser('manager', 'super'))
            ->test('audit.index')
            ->assertDontSee('전달 대기가 뭐야?');
    }

    public function test_ollama_가_죽어도_예외_대신_안내문을_답한다(): void
    {
        $this->fakeIndex([['source' => 's', 'text' => 't', 'embedding' => [1, 0]]]);
        $this->app->bind(OllamaClient::class, fn () => new class extends OllamaClient
        {
            public function __construct()
            {
                parent::__construct('http://fake');
            }

            public function embed(string $model, string $text): array
            {
                throw new \RuntimeException('Ollama 연결 실패');
            }
        });

        $res = app(AssistantService::class)->ask('질문', $this->mkUser('sales'));

        $this->assertSame(__('assistant.error'), $res['answer']);
    }

    public function test_권한_없는_역할은_위젯_전송이_403(): void
    {
        Volt::actingAs($this->mkUser('auction'))
            ->test('assistant.widget')
            ->set('q', '질문')
            ->call('send')
            ->assertForbidden();
    }

    public function test_기능설정에서_토글하면_setting_에_저장된다(): void
    {
        $this->fakeIndex([['source' => 's', 'text' => 't', 'embedding' => [1, 0]]]);
        Setting::updateOrCreate(['key' => 'assistant_enabled'], ['value' => '0', 'type' => 'boolean']);

        Volt::actingAs($this->mkUser('manager', 'super'))
            ->test('admin.settings')
            ->assertSee(__('assistant.settings_title'))
            ->set('assistantEnabled', true);

        $this->assertTrue((bool) Setting::get('assistant_enabled'));
    }

    public function test_색인이_아직_안_올라온_상태를_기능설정에서_경고한다(): void
    {
        // scp 대상에 board 가 추가되기 전 두 박스의 실제 초기 상태.
        config(['assistant.index_path' => '']);

        Volt::actingAs($this->mkUser('manager', 'super'))
            ->test('admin.settings')
            ->assertSee(__('assistant.settings_index_missing'));
    }

    public function test_빈_질문은_보내지_않는다(): void
    {
        Volt::actingAs($this->mkUser('sales'))
            ->test('assistant.widget')
            ->set('q', '   ')
            ->call('send')
            ->assertSet('messages', []);
    }
}
