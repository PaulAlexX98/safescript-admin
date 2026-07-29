<?php

namespace Tests\Unit;

use App\Models\ApprovedOrder;
use App\Models\Order;
use App\Services\Shipping\ClickAndDrop;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClickAndDropWalkInTest extends TestCase
{
    public function test_walk_in_markers_are_detected_without_treating_reorders_as_walk_ins(): void
    {
        $this->assertTrue((new Order([
            'meta' => ['source' => 'walk_in'],
        ]))->isWalkIn());

        $this->assertTrue((new Order([
            'meta' => ['appointment_type' => 'walk_in'],
        ]))->isWalkIn());

        $this->assertTrue((new Order([
            'meta' => ['is_walk_in' => true],
        ]))->isWalkIn());

        $this->assertFalse((new Order([
            'meta' => ['type' => 'reorder'],
        ]))->isWalkIn());
    }

    public function test_walk_in_order_never_sends_a_click_and_drop_request(): void
    {
        Http::fake();

        $order = new Order([
            'reference' => 'PWMR123456',
            'meta' => [
                'source' => 'walk_in',
                'appointment_type' => 'walk_in',
                'is_walk_in' => true,
            ],
        ]);

        $result = app(ClickAndDrop::class)->createOrder(
            $order,
            (object) ['first_name' => 'Walk', 'last_name' => 'In']
        );

        $this->assertTrue($result['skipped']);
        $this->assertSame('walk_in', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_approved_order_model_used_by_consultations_is_classified_safely(): void
    {
        $service = app(ClickAndDrop::class);

        $deliveryOrder = (new ApprovedOrder())->forceFill([
            'reference' => 'PWMR654321',
            'meta' => [
                'type' => 'reorder',
                'source' => 'website',
            ],
        ]);

        $walkInOrder = (new ApprovedOrder())->forceFill([
            'reference' => 'PWMN123456',
            'meta' => [
                'type' => 'new',
                'source' => 'walk_in',
                'appointment_type' => 'walk_in',
                'is_walk_in' => true,
            ],
        ]);

        $this->assertFalse($service->shouldSkipOrder($deliveryOrder));
        $this->assertTrue($service->shouldSkipOrder($walkInOrder));
    }

    public function test_delivery_order_still_sends_a_click_and_drop_request(): void
    {
        Http::fake([
            'https://clickanddrop.test/*' => Http::response([
                'createdOrders' => [],
            ]),
        ]);

        $order = (object) [
            'id' => 123,
            'reference' => 'PWMR654321',
            'meta' => ['source' => 'website'],
            'created_at' => now(),
            'user' => (object) [
                'shipping_address1' => '1 Test Street',
                'shipping_city' => 'Wakefield',
                'shipping_postcode' => 'WF1 1AA',
                'shipping_country' => 'GB',
                'email' => 'delivery@example.test',
                'phone' => '07123456789',
            ],
        ];

        $result = app(ClickAndDrop::class)->createOrder(
            $order,
            (object) [
                'first_name' => 'Delivery',
                'last_name' => 'Patient',
                'email' => 'delivery@example.test',
            ],
            [
                'base' => 'https://clickanddrop.test',
                'api_key' => 'test-key',
            ]
        );

        $this->assertArrayNotHasKey('skipped', $result);
        Http::assertSentCount(1);
    }
}
