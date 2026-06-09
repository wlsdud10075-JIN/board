<?php

namespace Database\Seeders;

use App\Models\PurchaseListing;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ─── 계정 5종 (역할별) · password ───
        $users = [
            ['관리자', 'admin@board.test', 'manager'],
            ['김영업', 'kim@board.test', 'sales'],
            ['이영업', 'lee@board.test', 'sales'],
            ['박검차', 'park@board.test', 'inspection'],
            ['최경매', 'choi@board.test', 'auction'],
        ];

        $byEmail = [];
        foreach ($users as [$name, $email, $role]) {
            $byEmail[$email] = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => $role,
                    'is_active' => true,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );
        }

        // ─── 샘플 매입예정 (김영업) — v2 목업 행 미러링 ───
        $kim = $byEmail['kim@board.test']->id;
        $rows = [
            // [차량번호, source, vin, 예상가, 최종금액, verdict, status, 부가필드]
            ['12가3456', 'encar', 'KMHE341TEST00001', 13_500_000, 13_200_000, 'accepted', 'accepted',
                ['encar_dealer' => '강남지점', 'buyer_name' => '드라간']],
            ['34나7890', 'auction', 'KNAJ881TEST00002', 8_900_000, 9_300_000, 'pending', 'awaiting_buyer',
                ['auction_venue' => '롯데', 'lot_number' => 'B-2210', 'buyer_name' => '드라간']],
            ['56다1234', 'encar', 'WBA5G7TEST000003', 21_000_000, null, 'none', 'draft',
                ['encar_dealer' => '분당지점']],
            ['78라5678', 'auction', 'MNTBB2TEST000004', 12_000_000, 11_500_000, 'accepted', 'accepted',
                ['auction_venue' => '현대 글로비스', 'lot_number' => 'C-3001', 'buyer_name' => '마르코']],
        ];

        foreach ($rows as [$vno, $source, $vin, $expected, $final, $verdict, $status, $extra]) {
            PurchaseListing::updateOrCreate(
                ['vin' => $vin],
                array_merge([
                    'created_by_user_id' => $kim,
                    'source' => $source,
                    'vehicle_number' => $vno,
                    'expected_price' => $expected,
                    'final_price' => $final,
                    'buyer_verdict' => $verdict,
                    'status' => $status,
                ], $extra),
            );
        }
    }
}
