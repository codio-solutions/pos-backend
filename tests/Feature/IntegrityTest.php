<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Visit;
use App\Support\InventoryReport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function product(int $qty = 10, int $bonus = 0): Product
    {
        $product = Product::create(['name' => 'Product', 'sku' => (string) Str::uuid(), 'trade_price' => '20.00']);
        if ($qty) {
            Purchase::create(['product_id' => $product->id, 'qty' => $qty, 'bonus_qty' => $bonus, 'value' => '100.00']);
        }

        return $product;
    }

    private function createJson(string $path, array $data, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/api'.$path, $data);
    }

    private function visit(array $extra = []): array
    {
        // A real tiny PNG avoids reliance on GD in the test runtime.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');

        return array_merge(['doctor_name' => 'Doctor', 'clinic_name' => 'Clinic', 'clinic_address' => 'Street', 'lat' => 24.8, 'lng' => 67,
            'doctor_photo' => UploadedFile::fake()->createWithContent('doctor.png', $png),
            'building_photo' => UploadedFile::fake()->createWithContent('building.png', $png)], $extra);
    }

    public function test_product_write_methods_require_admin(): void
    {
        $p = $this->product();
        $this->patchJson('/api/products/'.$p->id, ['name' => 'Changed'])->assertUnauthorized();
        $this->getJson('/api/products/'.$p->id)->assertUnauthorized();
        $this->loginAs('employee');
        $this->patchJson('/api/products/'.$p->id, ['name' => 'Changed'])->assertForbidden();
        $this->loginAs();
        $this->patchJson('/api/products/'.$p->id, ['name' => 'Changed'])->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'product.updated']);
    }

    public function test_invalid_visit_items_and_coordinates_create_no_files_or_rows(): void
    {
        Storage::fake('visits');
        $this->loginAs('employee');
        $p = $this->product();
        foreach (['false', '{', '[{}]', json_encode([['product_id' => $p->id, 'qty' => -2]]), json_encode([['product_id' => $p->id, 'qty' => 1.5]])] as $items) {
            $this->createJson('/visits', $this->visit(['items' => $items]))->assertStatus(422);
        }
        $this->createJson('/visits', $this->visit(['lat' => 95]))->assertStatus(422);
        $this->createJson('/visits', $this->visit(['travel_expense' => '-1']))->assertStatus(422);
        $this->assertDatabaseCount('visits', 0);
        $this->assertSame([], Storage::disk('visits')->allFiles());
    }

    public function test_visit_expenses_are_saved_and_visible_to_admin(): void
    {
        Storage::fake('visits');
        $this->loginAs('employee');

        $visit = $this->createJson('/visits', $this->visit([
            'travel_expense' => '125.50',
            'other_expense' => '35.25',
        ]))->assertCreated()
            ->assertJsonPath('travel_expense', '125.50')
            ->assertJsonPath('other_expense', '35.25');

        $this->assertDatabaseHas('visits', [
            'id' => $visit->json('id'),
            'travel_expense' => '125.50',
            'other_expense' => '35.25',
        ]);

        $this->loginAs();
        $this->getJson('/api/visits')
            ->assertOk()
            ->assertJsonPath('data.0.travel_expense', '125.50')
            ->assertJsonPath('data.0.other_expense', '35.25');
    }

    public function test_phone_and_order_are_optional_and_photos_are_private(): void
    {
        Storage::fake('visits');
        Storage::fake('public');
        $this->loginAs('employee');
        $result = $this->createJson('/visits', $this->visit())->assertCreated();
        Storage::disk('visits')->assertExists($result->json('doctor_photo_path'));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->get('/api/visits/'.$result->json('id').'/photos/doctor')->assertOk();
        $this->loginAs('employee');
        $this->getJson('/api/visits/'.$result->json('id').'/photos/doctor')->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_visit_failure_removes_uploaded_photos(): void
    {
        Storage::fake('visits');
        $this->loginAs('employee');
        $p = $this->product(0);
        $this->createJson('/visits', $this->visit(['items' => json_encode([['product_id' => $p->id, 'qty' => 1]])]))->assertStatus(422);
        $this->assertSame([], Storage::disk('visits')->allFiles());
        $this->assertDatabaseCount('visits', 0);
    }

    public function test_bonus_stock_overselling_inactive_products_and_idempotency(): void
    {
        $this->loginAs();
        $p = $this->product(10, 2);
        $this->assertSame(12, $p->currentStock());
        $payload = ['items' => [['product_id' => $p->id, 'qty' => 12, 'unit_price' => '20.00']]];
        $key = (string) Str::uuid();
        $one = $this->createJson('/orders', $payload, $key)->assertCreated();
        $two = $this->createJson('/orders', $payload, $key)->assertCreated();
        $this->assertEquals($one->json('id'), $two->json('id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(0, $p->currentStock());
        $payload['items'][0]['qty'] = 1;
        $this->createJson('/orders', $payload, $key)->assertStatus(409);
        $this->createJson('/orders', $payload)->assertStatus(422);
        $p->update(['is_active' => false]);
        $this->createJson('/orders', $payload)->assertStatus(422);
    }

    public function test_sale_returns_are_linked_bounded_and_priced_from_original_sale(): void
    {
        $this->loginAs();
        $p = $this->product();
        $order = $this->createJson('/orders', ['items' => [['product_id' => $p->id, 'qty' => 3, 'unit_price' => '20.00']]])->assertCreated()->json('id');
        $data = ['order_id' => $order, 'product_id' => $p->id, 'qty' => 2, 'value' => 999999];
        $this->createJson('/sale-returns', $data)->assertCreated()->assertJsonPath('value', 40);
        $this->createJson('/sale-returns', $data)->assertStatus(422);
        $this->assertSame(9, $p->currentStock());
        $this->assertDatabaseCount('sale_returns', 1);
    }

    public function test_purchase_return_cannot_exceed_original_or_available_stock(): void
    {
        $this->loginAs();
        $p = $this->product(10, 2);
        $purchase = Purchase::first();
        $this->createJson('/purchase-returns', ['purchase_id' => $purchase->id, 'product_id' => $p->id, 'qty' => 13])->assertStatus(422);
        $this->createJson('/purchase-returns', ['purchase_id' => $purchase->id, 'product_id' => $p->id, 'qty' => 12])->assertCreated();
        $this->assertSame(0, $p->currentStock());
    }

    public function test_purchase_record_rolls_back_if_ledger_write_fails(): void
    {
        $this->loginAs();
        $p = $this->product(0);
        StockMovement::creating(function () {
            throw new \RuntimeException('Simulated ledger failure');
        });
        try {
            $this->createJson('/purchases', ['product_id' => $p->id, 'qty' => 1, 'value' => 10])->assertStatus(500);
            $this->assertDatabaseCount('purchases', 0);
        } finally {
            StockMovement::flushEventListeners();
        }
    }

    public function test_inventory_value_is_cost_not_revenue_and_preserves_inactive_history(): void
    {
        $this->loginAs();
        $p = $this->product();
        $this->createJson('/orders', ['items' => [['product_id' => $p->id, 'qty' => 5, 'unit_price' => '30.00']]])->assertCreated();
        $p->update(['is_active' => false]);
        $report = (new InventoryReport)->generate(now()->toDateString(), now()->toDateString());
        $row = $report['rows']->first();
        $this->assertEquals(50, $row['closing']['value']);
        $this->assertEquals(150, $row['sale']['value']);
        $tomorrow = now()->addDay()->toDateString();
        $row = (new InventoryReport)->generate($tomorrow, $tomorrow)['rows']->first();
        $this->assertEquals(50, $row['opening']['value']);
    }

    private function webhook(array $data)
    {
        $body = json_encode($data);

        return $this->call('POST', '/api/orders/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, config('services.website.webhook_secret') ?? '')], $body);
    }

    public function test_webhook_fails_closed_then_reconciles_missing_sku_once(): void
    {
        config(['services.website.webhook_secret' => null]);
        $data = ['external_ref' => 'test-order', 'items' => [['sku' => 'missing', 'qty' => 1, 'unit_price' => '20.00']]];
        $this->webhook($data)->assertStatus(503);
        config(['services.website.webhook_secret' => str_repeat('s', 40)]);
        $response = $this->webhook($data)->assertOk()->assertJsonPath('status', 'pending');
        $this->assertNotNull(Order::first()->webhook_payload);
        $p = $this->product();
        $p->update(['sku' => 'missing']);
        $this->webhook($data)->assertOk()->assertJsonPath('status', 'completed');
        $this->webhook($data)->assertOk();
        $this->assertSame(9, $p->currentStock());
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_shift_close_uses_stored_start_and_summary_is_not_page_limited(): void
    {
        $user = $this->loginAs();
        $shift = Shift::create(['employee_id' => $user->id, 'check_in_at' => now()->subHours(2)]);
        $this->patchJson('/api/shifts/'.$shift->id.'/close', ['check_out_at' => '2000-01-01', 'check_in_at' => '1999-01-01', 'reason' => 'Correction'])->assertStatus(422);
        $this->patchJson('/api/shifts/'.$shift->id.'/close', ['check_out_at' => now()->subHour()->toIso8601String(), 'reason' => 'Correction'])->assertOk();
        for ($i = 0; $i < 55; $i++) {
            Shift::create(['employee_id' => $user->id, 'check_in_at' => now()->subHours(2), 'check_out_at' => now()->subHour()]);
        }
        $this->getJson('/api/shifts/mine')->assertOk()->assertJsonCount(25, 'shifts')->assertJsonPath('stats.completed_count', 56);
        $this->postJson('/api/shifts/check-in')->assertCreated();
        $this->postJson('/api/shifts/check-in')->assertStatus(422);
    }

    public function test_reset_revokes_tokens_and_link_is_single_use(): void
    {
        Mail::fake();
        $this->loginAs();
        $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
        $employee->createToken('old');
        $this->postJson('/api/employees/'.$employee->id.'/reset-password')->assertOk()->assertJsonMissingPath('temporary_password');
        $this->assertSame(0, $employee->tokens()->count());
        $token = Password::createToken($employee);
        $data = ['email' => $employee->email, 'token' => $token, 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123'];
        $this->postJson('/api/password/reset', $data)->assertOk();
        $this->postJson('/api/password/reset', $data)->assertStatus(422);
        $this->patchJson('/api/employees/'.$employee->id, ['is_active' => false])->assertOk();
        $this->postJson('/api/login', ['email' => $employee->email, 'password' => 'StrongPassword123'])->assertStatus(422);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/api/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_database_enforces_one_open_shift_per_employee(): void
    {
        $user = $this->loginAs('employee');
        Shift::create(['employee_id' => $user->id, 'check_in_at' => now()]);
        $this->expectException(QueryException::class);
        Shift::create(['employee_id' => $user->id, 'check_in_at' => now()]);
    }

    public function test_weighted_cost_includes_bonus_and_restores_return_cost(): void
    {
        $this->loginAs();
        $product = $this->product(10, 2);
        Purchase::create(['product_id' => $product->id, 'qty' => 8, 'bonus_qty' => 0, 'value' => '200.00']);
        // 20 units, cost 300: average cost 15, unrelated to selling price 40.
        $order = $this->createJson('/orders', ['items' => [['product_id' => $product->id, 'qty' => 4, 'unit_price' => '40.00']]])->assertCreated()->json('id');
        $this->createJson('/sale-returns', ['order_id' => $order, 'product_id' => $product->id, 'qty' => 2])->assertCreated();
        $row = (new InventoryReport)->generate(now()->toDateString(), now()->toDateString())['rows']->first();
        $this->assertEquals(18, $row['closing']['qty']);
        $this->assertEquals(270, $row['closing']['value']);
        $this->assertEquals(80, $row['net_sale']['value']);
    }

    public function test_private_photo_migration_verifies_then_removes_public_original(): void
    {
        Storage::fake('public');
        Storage::fake('visits');
        $employee = $this->loginAs('employee');
        Storage::disk('public')->put('visits/legacy.jpg', 'legacy-photo');
        Visit::create(['employee_id' => $employee->id, 'doctor_name' => 'Doctor', 'clinic_name' => 'Clinic', 'doctor_phone' => '', 'clinic_address' => 'Street', 'doctor_photo_path' => 'visits/legacy.jpg']);
        $this->artisan('visits:privatize-photos')->assertSuccessful();
        Storage::disk('public')->assertExists('visits/legacy.jpg');
        $this->artisan('visits:privatize-photos', ['--apply' => true])->assertSuccessful();
        Storage::disk('public')->assertMissing('visits/legacy.jpg');
        $this->assertSame('legacy-photo', Storage::disk('visits')->get('visits/legacy.jpg'));
    }
}
