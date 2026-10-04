<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Visit;
use App\Models\VisitOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VisitController extends Controller
{
    /**
     * Employee submits a visit. Sent as multipart/form-data (not JSON)
     * because of the two required photo uploads — `items` arrives as a
     * JSON-encoded string field rather than nested form fields, since
     * that's the simplest way to carry a variable-length array alongside
     * file uploads in one multipart request.
     *
     * Doctor/clinic fields are always required; `items` is optional —
     * omit it (or send "[]") for a visit with no order. When items are
     * present, this creates a matching `orders` row (source: visit)
     * through the same Order::complete() pipeline the website webhook
     * and POS checkout use, so stock decrements the same way regardless
     * of where the sale came from.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'doctor_name' => 'required|string|max:255',
            'clinic_name' => 'required|string|max:255',
            'doctor_phone' => 'required|string|max:50',
            'doctor_email' => 'nullable|email',
            'clinic_address' => 'required|string',
            'notes' => 'nullable|string',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'items' => 'nullable|string', // JSON-encoded array, decoded below
            'doctor_photo' => 'required|image|max:5120',
            'building_photo' => 'required|image|max:5120',
        ]);

        $items = [];
        if (!empty($data['items'])) {
            $decoded = json_decode($data['items'], true);
            abort_if(json_last_error() !== JSON_ERROR_NONE, 422, 'Invalid items payload.');
            $items = $decoded ?? [];
        }

        $doctorPhotoPath = $request->file('doctor_photo')->store('visits', 'public');
        $buildingPhotoPath = $request->file('building_photo')->store('visits', 'public');

        $visit = DB::transaction(function () use ($data, $items, $doctorPhotoPath, $buildingPhotoPath, $request) {
            $visit = Visit::create([
                'employee_id' => $request->user()->id,
                'doctor_name' => $data['doctor_name'],
                'clinic_name' => $data['clinic_name'],
                'doctor_phone' => $data['doctor_phone'],
                'doctor_email' => $data['doctor_email'] ?? null,
                'clinic_address' => $data['clinic_address'],
                'notes' => $data['notes'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'doctor_photo_path' => $doctorPhotoPath,
                'building_photo_path' => $buildingPhotoPath,
            ]);

            if (!empty($items)) {
                $order = Order::create([
                    'source' => 'visit',
                    'status' => 'pending',
                    'created_by' => $request->user()->id,
                ]);

                foreach ($items as $item) {
                    $product = Product::findOrFail($item['product_id']);

                    VisitOrderItem::create([
                        'visit_id' => $visit->id,
                        'product_id' => $product->id,
                        'qty' => $item['qty'],
                    ]);

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'qty' => $item['qty'],
                        'unit_price' => $product->trade_price,
                        'line_total' => $item['qty'] * $product->trade_price,
                    ]);
                }

                $order->load('items');
                $order->complete();
            }

            return $visit;
        });

        return response()->json($visit->load('orderItems.product'), 201);
    }

    /**
     * Own visit history — backs the employee portal's "My Visits" page.
     */
    public function mine(Request $request)
    {
        $visits = Visit::with('orderItems.product')
            ->where('employee_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get();

        return response()->json($visits);
    }

    /**
     * Admin-only: cross-employee visit log, filterable by employee/doctor/date.
     * Backs both "Visits" and "Submitted Data" — same data, the sidebar
     * just gives the client two entry points into it.
     */
    public function index(Request $request)
    {
        $visits = Visit::with('employee:id,name', 'orderItems.product')
            ->when($request->employee_id, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($request->doctor, fn ($q, $d) => $q->where('doctor_name', 'like', "%{$d}%"))
            ->when($request->from, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate(25);

        return response()->json($visits);
    }
}
