<?php

namespace Tests\Feature;

use Tests\TestCase;

class NotionGuideAudienceTest extends TestCase
{
    public function test_board_parent_builder_emits_staff_audience_marker(): void
    {
        $path = base_path('scripts/notion-guide-publish.php');
        if (! file_exists($path)) {
            $this->markTestSkipped('Notion 발행 스크립트가 없는 브랜치입니다.');
        }

        $source = file_get_contents($path);
        $body = $this->functionBody($source, 'blocks_board_parent');

        $this->assertNotSame('', $body, 'blocks_board_parent()를 찾지 못했습니다.');
        $this->assertSame(1, substr_count($body, "para('ASSISTANT_AUDIENCE=staff')"),
            'BOARD 루트 페이지의 staff 마커가 없거나 중복입니다. 마커 없이 발행하면 하위 가이드 색인이 fail-closed로 멈춥니다.');
    }

    private function functionBody(string $source, string $name): string
    {
        $position = strpos($source, "function {$name}(");
        if ($position === false) {
            return '';
        }

        $open = strpos($source, '{', $position);
        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $position, $i - $position + 1);
                }
            }
        }

        return '';
    }
}
