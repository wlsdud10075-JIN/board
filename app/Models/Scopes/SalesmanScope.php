<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 영업 본인격리 — role=sales 인 사용자는 본인이 만든 매입예정만 조회.
 * **관리(role=manager, 비-full-access)는 본인 + 자기를 공유한 영업(`manager_salesman`)의 매물만**(2026-11-02 Jin — 「매입예정도 본인과 연결된 영업만」,
 * ERP 관리 스코프와 같은 맥락). 공유가 0명이면 본인 등록분만 보인다. 검차/경매 role·super·업무관리자·비인증(콘솔/시더)은 전체 노출.
 *
 * 모델레이어 Global Scope 로 강제해 컴포넌트마다 수동 when() 누락형 IDOR 을 구조적으로 차단. 매입예정·전달·회신·경매·관리 화면이 전부 이 스코프를 탄다.
 */
class SalesmanScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        // 시스템관리자(super)·업무관리자는 예외(전체 노출) — 메뉴는 전체인데 목록만 본인 것이면 어긋난다.
        if (! $user || $user->hasFullAccess()) {
            return;
        }
        $col = $model->getTable().'.created_by_user_id';
        if ($user->role === 'sales') {
            $builder->where($col, $user->id);
        } elseif ($user->role === 'manager') {
            // 서브쿼리 하나 — 매 요청마다 pivot 을 따로 읽지 않는다. 공유 0명이면 본인 것만.
            $builder->where(fn ($q) => $q->where($col, $user->id)
                ->orWhereIn($col, DB::table('manager_salesman')->select('salesman_user_id')->where('manager_user_id', $user->id)));
        }
    }
}
