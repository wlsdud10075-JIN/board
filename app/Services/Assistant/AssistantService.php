<?php

namespace App\Services\Assistant;

use App\Models\BoardAuditLog;
use App\Models\User;

/**
 * 사내 업무 도우미 — A(업무가이드 RAG) 전용.
 *
 * 질문 → bge-m3 임베딩 → index-board.json 코사인 top-k → qwen3:8b 가 "참고자료 근거로만" 답변.
 * car-erp 의 B(미수·채권·자금 조회)는 그 데이터가 car-erp 원장에 있으므로 board 엔 없다.
 *
 * 열람 대상이 영업·관리·super 로 한정돼 색인 청크별 등급(audience) 필터는 두지 않는다
 * (= 볼 수 있는 사람은 37장 카드 전부를 본다, jin 2026-08-03).
 */
class AssistantService
{
    public function __construct(private OllamaClient $ollama) {}

    /** @return array{kind:string,answer:string,sources?:array} */
    public function ask(string $question, User $user): array
    {
        $question = trim($question);
        if ($question === '') {
            return ['kind' => 'error', 'answer' => __('assistant.empty_question')];
        }

        $result = $this->guide($question);

        // 감사기록 — 매물 변경이 아니므로 purchase_listing_id 는 null.
        BoardAuditLog::create([
            'user_id' => $user->id,
            'action' => 'assistant_query',
            'new_value' => mb_substr($question, 0, 500),
        ]);

        return $result;
    }

    /** @return array{kind:string,answer:string,sources?:array} */
    private function guide(string $question): array
    {
        $path = (string) config('assistant.index_path');
        if ($path === '' || ! is_file($path)) {
            return ['kind' => 'guide', 'answer' => __('assistant.no_index')];
        }
        $kb = json_decode((string) file_get_contents($path), true) ?: [];
        if (! $kb) {
            return ['kind' => 'guide', 'answer' => __('assistant.no_index')];
        }

        try {
            $qEmb = $this->ollama->embed((string) config('assistant.emb_model'), $question);
            if (! $qEmb) {
                throw new \RuntimeException('임베딩 실패');
            }

            $scored = [];
            foreach ($kb as $i => $doc) {
                $scored[$i] = $this->cosine($qEmb, $doc['embedding'] ?? []);
            }
            arsort($scored);
            $top = array_slice($scored, 0, (int) config('assistant.rag_topk', 3), true);

            $ctx = '';
            $sources = [];
            foreach ($top as $i => $score) {
                $ctx .= "### {$kb[$i]['source']}\n{$kb[$i]['text']}\n\n";
                $sources[] = ['title' => $kb[$i]['source'], 'score' => round($score, 3)];
            }

            // 색인이 한국어라 시스템 프롬프트도 한국어 고정(번역 대상 아님).
            $sys = '당신은 SSANCAR 매입보드(BOARD) 사내 업무 도우미다. 반드시 아래 [참고자료]를 근거로 한국어로 간결·정확하게 답하라. '
                .'질문과 관련된 규칙·절차·금지·예외·주의사항이 참고자료에 있으면 그것을 바탕으로 분명히 답하라. '
                .'참고자료 어디에도 관련 내용이 전혀 없을 때만 "해당 내용은 등록된 업무 가이드에 없습니다."라고 답하라. 지어내지 마라.';
            $answer = $this->ollama->chat((string) config('assistant.llm_model'), $sys, "[참고자료]\n{$ctx}\n[질문]\n{$question}");

            return ['kind' => 'guide', 'answer' => $answer ?: __('assistant.no_answer'), 'sources' => $sources];
        } catch (\Throwable) {
            return ['kind' => 'error', 'answer' => __('assistant.error')];
        }
    }

    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $v) {
            $bv = $b[$i] ?? 0;
            $dot += $v * $bv;
            $na += $v * $v;
            $nb += $bv * $bv;
        }

        return ($na && $nb) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
