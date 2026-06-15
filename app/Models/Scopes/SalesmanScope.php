<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * 영업 본인격리 — role=sales 인 사용자는 본인이 만든 매입예정만 조회.
 * 검차/경매/관리 및 비인증(콘솔/시더)은 전체 노출.
 *
 * 모델레이어 Global Scope 로 강제해 컴포넌트마다 수동 when() 누락형 IDOR 을 구조적으로 차단.
 */
class SalesmanScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        // 영업 role 만 본인격리. 시스템관리자(super)는 예외(전체 노출).
        if ($user && $user->role === 'sales' && ! $user->isSuper()) {
            $builder->where($model->getTable().'.created_by_user_id', $user->id);
        }
    }
}
