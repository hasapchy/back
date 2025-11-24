<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Repositories\OrdersRepository;
use Illuminate\Http\Request;

class OrderService
{
    /**
     * @var OrdersRepository
     */
    protected $repository;

    /**
     * @var CategoryAccessService
     */
    protected $categoryAccessService;

    /**
     * @param OrdersRepository $repository
     * @param CategoryAccessService $categoryAccessService
     */
    public function __construct(OrdersRepository $repository, CategoryAccessService $categoryAccessService)
    {
        $this->repository = $repository;
        $this->categoryAccessService = $categoryAccessService;
    }

    /**
     * Создать заказ
     *
     * @param array $data
     * @param User $user
     * @param int|null $companyId
     * @return Order
     */
    public function createOrder(array $data, User $user, ?int $companyId = null): Order
    {
        $data['category_id'] = $this->categoryAccessService->normalizeCategoryIdForWorker(
            $user,
            $data['category_id'] ?? null,
            $companyId
        );

        if ($data['category_id'] === null && $user->hasRole(config('basement.worker_role'))) {
            throw new \Exception('У вас нет доступа к указанной категории');
        }

        $data['user_id'] = $user->id;
        $data['status_id'] = $data['status_id'] ?? 1;

        $order = $this->repository->createItem($data);

        return Order::with([
            'client', 'user', 'status', 'category', 'cash', 'warehouse', 'project',
            'orderProducts.product', 'orderProducts.product.unit'
        ])->findOrFail($order->id);
    }

    /**
     * Обновить заказ
     *
     * @param Order $order
     * @param array $data
     * @param User $user
     * @param int|null $companyId
     * @return Order
     */
    public function updateOrder(Order $order, array $data, User $user, ?int $companyId = null): Order
    {
        if (isset($data['category_id'])) {
            $data['category_id'] = $this->categoryAccessService->normalizeCategoryIdForWorker(
                $user,
                $data['category_id'],
                $companyId
            );

            if ($data['category_id'] === null && $user->hasRole(config('basement.worker_role'))) {
                throw new \Exception('У вас нет доступа к указанной категории');
            }
        }

        $this->repository->updateItem($order->id, $data);

        return Order::with([
            'client', 'user', 'status', 'category', 'cash', 'warehouse', 'project',
            'orderProducts.product', 'orderProducts.product.unit'
        ])->findOrFail($order->id);
    }

    /**
     * Подготовить данные заказа из запроса
     *
     * @param Request $request
     * @param User $user
     * @return array
     */
    public function prepareOrderData(Request $request, User $user): array
    {
        return [
            'user_id' => $user->id,
            'client_id' => $request->client_id,
            'project_id' => $request->project_id,
            'cash_id' => $request->cash_id,
            'warehouse_id' => $request->warehouse_id,
            'currency_id' => $request->currency_id,
            'category_id' => $request->category_id,
            'discount' => $request->discount ?? 0,
            'discount_type' => $request->discount_type ?? 'percent',
            'description' => $request->description ?? '',
            'date' => $request->date ?? now(),
            'note' => $request->note ?? '',
            'status_id' => $request->status_id ?? 1,
            'products' => $this->prepareProducts($request->products ?? []),
            'temp_products' => $this->prepareTempProducts($request->temp_products ?? []),
            'remove_temp_products' => $request->remove_temp_products ?? [],
        ];
    }

    /**
     * Подготовить данные продуктов
     *
     * @param array $products
     * @return array
     */
    protected function prepareProducts(array $products): array
    {
        return array_map(fn($p) => [
            'id' => $p['id'] ?? null,
            'product_id' => $p['product_id'],
            'quantity' => $p['quantity'],
            'price' => $p['price'],
            'width' => $p['width'] ?? null,
            'height' => $p['height'] ?? null,
        ], $products);
    }

    /**
     * Подготовить данные временных продуктов
     *
     * @param array $tempProducts
     * @return array
     */
    protected function prepareTempProducts(array $tempProducts): array
    {
        return array_map(fn($p) => [
            'id' => $p['id'] ?? null,
            'name' => $p['name'],
            'description' => $p['description'] ?? null,
            'quantity' => $p['quantity'],
            'price' => $p['price'],
            'unit_id' => $p['unit_id'] ?? null,
            'width' => $p['width'] ?? null,
            'height' => $p['height'] ?? null,
        ], $tempProducts);
    }
}

