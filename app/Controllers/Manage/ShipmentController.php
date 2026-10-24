<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\NinjaVanSettings;
use App\Services\OrderService;
use App\Services\Shipping\NinjaVanGateway;
use App\Services\ShipmentService;

/** NinjaVan shipments from the order page. create/cancel need orders.update, the waybill needs orders.view. */
final class ShipmentController extends ManageController
{
    public function create(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $back = $this->base() . "/orders/{$order['id']}#shipping";
        $in = $request->body;
        $r = NinjaVanGateway::create((int) $order['id'], $in, (int) Auth::user()['id']);
        if (!$r['ok']) {
            $this->backWithErrors($back, ($r['fields'] ?? []) + ['form' => $r['error']], array_intersect_key($in, array_flip(['weight', 'service_level', 'delivery_date', 'delivery_slot', 'pickup', 'pickup_date', 'pickup_slot', 'volume'])));
        }
        // Back to the Processing list: the parcel is booked, the order waits there until the AWB is printed.
        $this->success($this->base() . '/orders?status=processing', "Shipment created. Tracking number {$r['tracking']}.");
    }

    public function cancel(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $back = $this->base() . "/orders/{$order['id']}#shipping";
        $r = NinjaVanGateway::cancel((int) $order['id'], (int) Auth::user()['id']);
        $r['ok'] ? $this->success($back, 'Shipment cancelled.') : $this->backWithErrors($back, ['form' => $r['error']]);
    }

    /** Our own printable AWB (layout of the courier label). Staff with orders.view may print it. */
    public function awb(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $ship = ShipmentService::active((int) $order['id']) ?? Response::abort(404);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        $this->view('manage/orders/awb', [
            'base' => $this->base(),
            'order' => $order,
            'ship' => $ship,
            'items' => NinjaVanSettings::showItemsOnAwb() ? OrderService::items((int) $order['id']) : null,
            'sender' => NinjaVanSettings::sender(),
            'autoPrint' => (string) $request->input('print', '') === '1',
        ], null);
    }

    /** Several labels on one print run: only Processing orders that have a tracking number. */
    public function bulk(Request $request): void
    {
        $ids = $this->ids($request);
        $orders = OrderService::findAmong($ids);
        $ships = ShipmentService::activeAmong($ids);
        $ready = array_values(array_filter($ids, fn($id) => isset($orders[$id], $ships[$id]) && $orders[$id]['status'] === 'processing'));
        if ($ready === []) {
            $this->backWithErrors($this->base() . '/orders?status=processing', ['form' => 'None of the selected orders is ready to print. Only Processing orders with a tracking number can be printed.']);
        }
        $items = NinjaVanSettings::showItemsOnAwb() ? OrderService::itemsAmong($ready) : [];
        $labels = array_map(fn($id) => [
            'order' => $orders[$id],
            'ship' => $ships[$id],
            'items' => NinjaVanSettings::showItemsOnAwb() ? ($items[$id] ?? []) : null,
        ], $ready);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        $this->view('manage/orders/awb_bulk', [
            'base' => $this->base(),
            'labels' => $labels,
            'sender' => NinjaVanSettings::sender(),
            'printedIds' => implode(',', $ready),
            'autoPrint' => (string) $request->input('print', '') === '1',
        ], null);
    }

    /** Several labels were sent to the printer (called by the bulk page). */
    public function awbPrintedAmong(Request $request): void
    {
        Response::json(['ok' => true, 'marked' => ShipmentService::markAwbPrintedAmong($this->ids($request))]);
    }

    /** The label was sent to the printer (called by the AWB page). */
    public function awbPrinted(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        Response::json(['ok' => ShipmentService::markAwbPrinted((int) $order['id'])]);
    }

    /** Order ids from "ids=1,2,3" (at most 50, digits only). @return int[] */
    private function ids(Request $request): array
    {
        return OrderService::idsFrom((string) $request->input('ids', ''));
    }

    public function waybill(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $s = ShipmentService::active((int) $order['id']) ?? Response::abort(404);
        $r = NinjaVanGateway::waybill($s);
        if (!$r['ok']) {
            $this->backWithErrors($this->base() . "/orders/{$order['id']}#shipping", ['form' => $r['error']]);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="waybill-' . $s['tracking_number'] . '.pdf"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($r['file']);
        exit;
    }
}
