<?php

namespace App\Http\Controllers;

use App\Services\Guides\GuideCatalog;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 업무 가이드(/guides) — 로그인한 전 직원(활성 계정)이 본다 (Jin 2026-11-13, 화면 = car-erp 목업 docs/design/mock/2026-11-13/guides-page.html 과 같게).
 * 본문·그림은 public 이 아니라 resources/guides 에 있고 여기서만 내보낸다(검색엔진 차단 헤더).
 * 역할 미들웨어를 안 거는 화면이라 비활성 계정은 여기서 막는다(다른 업무 화면과 같은 403).
 */
class GuideController extends Controller
{
    public function index(): View
    {
        abort_unless((bool) auth()->user()?->is_active, 403);

        return view('guides.index', [
            // 묶음 순서는 config, 이름·화면 문구는 언어 파일(영어 화면이면 영어로).
            'payload' => [
                'scenes' => GuideCatalog::all(),
                'groups' => array_map(fn ($k) => __('guides.groups.'.$k), array_combine(array_keys(config('guides.groups')), array_keys(config('guides.groups')))),
                'base' => url('guides'),
                't' => __('guides'),
            ],
            'erpUrl' => GuideCatalog::erpUrl(),
        ]);
    }

    public function show(string $slug): View
    {
        abort_unless((bool) auth()->user()?->is_active, 403);
        $scene = GuideCatalog::find($slug);
        abort_if($scene === null, 404);

        return view('guides.show', [
            'scene' => $scene,
            // 본문은 그림을 파일명만으로 적는다(src="01.png") — 여기서 이 씬의 그림 주소로 바꾼다.
            'html' => preg_replace('/src="([A-Za-z0-9_-]+\.(?:png|jpg|webp))"/', 'src="'.url('guides/'.$slug).'/$1"', GuideCatalog::html($slug)),
            'near' => GuideCatalog::neighbours($slug),
        ]);
    }

    public function asset(string $slug, string $file): BinaryFileResponse
    {
        abort_unless((bool) auth()->user()?->is_active, 403);
        $path = GuideCatalog::asset($slug, $file);
        abort_if($path === null || GuideCatalog::find($slug) === null, 404);

        return response()->file($path, ['X-Robots-Tag' => 'noindex', 'Cache-Control' => 'private, max-age=3600']);
    }
}
