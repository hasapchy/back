<?php

namespace Tests\Unit;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\TransactionCategory;
use App\Models\TransactionCategoryBinding;
use App\Models\User;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Services\DocumentParentBalanceResolver;
use App\Services\TransactionCategoryBindingResolver;
use App\Support\TransactionCategoryBindingKeys;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DocumentParentBalanceResolverTest extends TestCase
{

    private DocumentParentBalanceResolver $resolver;

    private Company $company;

    private User $user;

    private Currency $currency;

    private Client $client;

    private int $goodsCategoryId = 6;

    private int $deliveryCategoryId = 16;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(DocumentParentBalanceResolver::class);
        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->currency = Currency::factory()->create([
            'company_id' => $this->company->id,
            'is_default' => true,
            'is_report' => true,
        ]);
        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->user->id,
        ]);
        $this->seedReceiptCategoryBindings();
    }

    public function test_resolve_returns_order_client_balance_id(): void
    {
        $balance = $this->createBalance();
        $order = $this->createOrder(['client_balance_id' => $balance->id]);

        $this->assertSame($balance->id, $this->resolver->resolve($order->id, null, null));
    }

    public function test_is_document_linked_for_order_and_wh_receipt_source(): void
    {
        $this->assertTrue($this->resolver->isDocumentLinked(1, null, null));
        $this->assertTrue($this->resolver->isDocumentLinked(null, 'App\\Models\\WhReceipt', 5));
        $this->assertFalse($this->resolver->isDocumentLinked(null, null, null));
    }

    public function test_manual_payment_rejects_is_debt_for_order(): void
    {
        $order = $this->createOrder();

        $validator = $this->runManualPaymentValidation([
            'order_id' => $order->id,
            'is_debt' => true,
            'client_balance_id' => null,
        ]);

        $this->assertTrue($validator->errors()->has('is_debt'));
    }

    public function test_wh_receipt_manual_payment_allows_is_debt(): void
    {
        $receipt = WhReceipt::factory()->create([
            'supplier_id' => $this->client->id,
            'creator_id' => $this->user->id,
        ]);

        $validator = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'category_id' => $this->deliveryCategoryId,
            'is_debt' => true,
            'client_balance_id' => null,
        ]);

        $this->assertFalse($validator->errors()->has('is_debt'));
    }

    public function test_manual_payment_requires_balance_when_order_has_client_balance_id(): void
    {
        $balance = $this->createBalance();
        $order = $this->createOrder(['client_balance_id' => $balance->id]);

        $missing = $this->runManualPaymentValidation([
            'order_id' => $order->id,
            'is_debt' => false,
            'client_balance_id' => null,
        ]);
        $this->assertTrue($missing->errors()->has('client_balance_id'));

        $wrong = $this->runManualPaymentValidation([
            'order_id' => $order->id,
            'is_debt' => false,
            'client_balance_id' => $this->createBalance()->id,
        ]);
        $this->assertTrue($wrong->errors()->has('client_balance_id'));

        $ok = $this->runManualPaymentValidation([
            'order_id' => $order->id,
            'is_debt' => false,
            'client_balance_id' => $balance->id,
        ]);
        $this->assertFalse($ok->errors()->has('client_balance_id'));
    }

    public function test_manual_payment_requires_balance_for_order_with_client_but_without_document_balance(): void
    {
        $order = $this->createOrder(['client_balance_id' => null]);
        $balance = $this->createBalance();

        $missing = $this->runManualPaymentValidation([
            'order_id' => $order->id,
            'is_debt' => false,
            'client_balance_id' => null,
        ]);
        $this->assertTrue($missing->errors()->has('client_balance_id'));

        $otherClientBalance = ClientBalance::query()->create([
            'client_id' => Client::factory()->create([
                'company_id' => $this->company->id,
                'creator_id' => $this->user->id,
            ])->id,
            'currency_id' => $this->currency->id,
            'type' => 1,
            'balance' => 0,
        ]);
        $foreign = $this->runManualPaymentValidation([
            'order_id' => $order->id,
            'is_debt' => false,
            'client_balance_id' => $otherClientBalance->id,
        ]);
        $this->assertTrue($foreign->errors()->has('client_balance_id'));

        $ok = $this->runManualPaymentValidation([
            'order_id' => $order->id,
            'is_debt' => false,
            'client_balance_id' => $balance->id,
        ]);
        $this->assertFalse($ok->errors()->has('client_balance_id'));
    }

    public function test_manual_payment_skips_validation_without_document_link(): void
    {
        $validator = $this->runManualPaymentValidation([
            'is_debt' => false,
            'client_balance_id' => null,
        ]);

        $this->assertFalse($validator->errors()->has('client_balance_id'));
        $this->assertFalse($validator->errors()->has('is_debt'));
    }

    public function test_wh_receipt_with_supplier_requires_balance_when_no_document_balance_id(): void
    {
        $balance = $this->createBalance();
        $receipt = WhReceipt::factory()->create([
            'supplier_id' => $this->client->id,
            'client_balance_id' => null,
            'creator_id' => $this->user->id,
        ]);

        $missing = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'category_id' => $this->goodsCategoryId,
            'is_debt' => false,
            'client_balance_id' => null,
        ]);
        $this->assertTrue($missing->errors()->has('client_balance_id'));

        $ok = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'category_id' => $this->goodsCategoryId,
            'is_debt' => false,
            'client_balance_id' => $balance->id,
        ]);
        $this->assertFalse($ok->errors()->has('client_balance_id'));
    }

    public function test_wh_receipt_goods_payment_rejects_foreign_client_balance(): void
    {
        $receipt = WhReceipt::factory()->create([
            'supplier_id' => $this->client->id,
            'client_balance_id' => null,
            'creator_id' => $this->user->id,
        ]);
        $foreignClient = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->user->id,
        ]);
        $foreignBalance = ClientBalance::query()->create([
            'client_id' => $foreignClient->id,
            'currency_id' => $this->currency->id,
            'type' => 1,
            'balance' => 0,
        ]);

        $validator = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'category_id' => $this->goodsCategoryId,
            'is_debt' => false,
            'client_balance_id' => $foreignBalance->id,
        ]);

        $this->assertTrue($validator->errors()->has('client_balance_id'));
    }

    public function test_wh_receipt_delivery_expense_without_balance_does_not_require_balance(): void
    {
        $receipt = WhReceipt::factory()->create([
            'supplier_id' => $this->client->id,
            'client_balance_id' => null,
            'creator_id' => $this->user->id,
        ]);

        $validator = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'category_id' => $this->deliveryCategoryId,
            'is_debt' => false,
            'client_balance_id' => null,
        ]);

        $this->assertFalse($validator->errors()->has('client_balance_id'));
    }

    public function test_wh_receipt_logistics_allows_balance_other_than_document_balance(): void
    {
        $documentBalance = $this->createBalance();
        $otherBalance = $this->createAlternateClientBalance();
        $receipt = WhReceipt::factory()->create([
            'supplier_id' => $this->client->id,
            'client_balance_id' => $documentBalance->id,
            'creator_id' => $this->user->id,
        ]);

        $validator = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'category_id' => $this->deliveryCategoryId,
            'is_debt' => false,
            'client_balance_id' => $otherBalance->id,
        ]);

        $this->assertFalse($validator->errors()->has('client_balance_id'));
    }

    public function test_wh_receipt_delivery_credit_allows_balance_of_client_other_than_supplier(): void
    {
        $receipt = WhReceipt::factory()->create([
            'supplier_id' => $this->client->id,
            'client_balance_id' => null,
            'creator_id' => $this->user->id,
        ]);
        $driver = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->user->id,
        ]);
        $driverBalance = ClientBalance::query()->create([
            'client_id' => $driver->id,
            'currency_id' => $this->currency->id,
            'type' => 1,
            'balance' => 0,
        ]);

        $validator = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'category_id' => $this->deliveryCategoryId,
            'is_debt' => true,
            'client_balance_id' => $driverBalance->id,
        ]);

        $this->assertFalse($validator->errors()->has('client_balance_id'));
    }

    public function test_wh_receipt_without_supplier_does_not_require_balance(): void
    {
        $receipt = WhReceipt::factory()->create([
            'supplier_id' => null,
            'client_balance_id' => null,
            'creator_id' => $this->user->id,
        ]);

        $validator = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'is_debt' => false,
            'client_balance_id' => null,
        ]);

        $this->assertFalse($validator->errors()->has('client_balance_id'));
    }

    public function test_resolve_payee_client_id_for_order_contract_purchase_receipt(): void
    {
        $order = $this->createOrder();
        $this->assertSame(
            $this->client->id,
            $this->resolver->resolvePayeeClientId($order->id, null, null)
        );

        $project = Project::factory()->create(['client_id' => $this->client->id]);
        $contract = ProjectContract::factory()->create([
            'project_id' => $project->id,
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
        ]);
        $this->assertSame(
            $this->client->id,
            $this->resolver->resolvePayeeClientId(null, 'App\\Models\\ProjectContract', $contract->id)
        );

        $purchase = WhPurchase::query()->create([
            'supplier_id' => $this->client->id,
            'cash_id' => CashRegister::factory()->create(['company_id' => $this->company->id, 'currency_id' => $this->currency->id])->id,
            'currency_id' => $this->currency->id,
            'creator_id' => $this->user->id,
            'status' => 'draft',
            'date' => now(),
            'amount' => 100,
        ]);
        $this->assertSame(
            $this->client->id,
            $this->resolver->resolvePayeeClientId(null, 'App\\Models\\WhPurchase', $purchase->id)
        );

        $receipt = WhReceipt::factory()->create([
            'supplier_id' => $this->client->id,
            'creator_id' => $this->user->id,
        ]);
        $this->assertSame(
            $this->client->id,
            $this->resolver->resolvePayeeClientId(null, 'App\\Models\\WhReceipt', $receipt->id)
        );
    }

    public function test_wh_purchase_with_supplier_requires_matching_or_present_balance(): void
    {
        $balance = $this->createBalance();
        $purchase = WhPurchase::query()->create([
            'supplier_id' => $this->client->id,
            'client_balance_id' => $balance->id,
            'cash_id' => \App\Models\CashRegister::factory()->create([
                'company_id' => $this->company->id,
                'currency_id' => $this->currency->id,
            ])->id,
            'currency_id' => $this->currency->id,
            'creator_id' => $this->user->id,
            'status' => 'draft',
            'date' => now(),
            'amount' => 100,
        ]);

        $bad = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\WhPurchase',
            'source_id' => $purchase->id,
            'is_debt' => false,
            'client_balance_id' => $this->createBalance()->id,
        ]);
        $this->assertTrue($bad->errors()->has('client_balance_id'));

        $ok = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\WhPurchase',
            'source_id' => $purchase->id,
            'is_debt' => false,
            'client_balance_id' => $balance->id,
        ]);
        $this->assertFalse($ok->errors()->has('client_balance_id'));
    }

    public function test_project_contract_with_client_balance_id_must_match(): void
    {
        $balance = $this->createBalance();
        $otherBalance = $this->createBalance();
        $project = Project::factory()->create(['client_id' => $this->client->id]);
        $contract = ProjectContract::factory()->create([
            'project_id' => $project->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $balance->id,
            'currency_id' => $this->currency->id,
        ]);

        $bad = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\ProjectContract',
            'source_id' => $contract->id,
            'is_debt' => false,
            'client_balance_id' => $otherBalance->id,
        ]);
        $this->assertTrue($bad->errors()->has('client_balance_id'));

        $ok = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\ProjectContract',
            'source_id' => $contract->id,
            'is_debt' => false,
            'client_balance_id' => $balance->id,
        ]);
        $this->assertFalse($ok->errors()->has('client_balance_id'));
    }

    public function test_wh_receipt_goods_category_resolved_from_company_binding(): void
    {
        $customGoodsCategory = TransactionCategory::factory()->create([
            'type' => 0,
            'creator_id' => $this->user->id,
        ]);
        TransactionCategoryBinding::query()->updateOrCreate(
            [
                'company_id' => $this->company->id,
                'binding_key' => TransactionCategoryBindingKeys::WAREHOUSE_RECEIPT,
            ],
            ['transaction_category_id' => $customGoodsCategory->id],
        );

        $resolver = new DocumentParentBalanceResolver(new TransactionCategoryBindingResolver);
        $balance = $this->createBalance();
        $receipt = WhReceipt::factory()->create([
            'supplier_id' => $this->client->id,
            'client_balance_id' => null,
            'creator_id' => $this->user->id,
        ]);

        $missing = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'category_id' => $customGoodsCategory->id,
            'is_debt' => false,
            'client_balance_id' => null,
            'company_id' => $this->company->id,
        ], $resolver);
        $this->assertTrue($missing->errors()->has('client_balance_id'));

        $ok = $this->runManualPaymentValidation([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'category_id' => $customGoodsCategory->id,
            'is_debt' => false,
            'client_balance_id' => $balance->id,
            'company_id' => $this->company->id,
        ], $resolver);
        $this->assertFalse($ok->errors()->has('client_balance_id'));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function runManualPaymentValidation(
        array $input,
        ?DocumentParentBalanceResolver $resolver = null,
    ): \Illuminate\Contracts\Validation\Validator {
        $validator = Validator::make([], []);
        ($resolver ?? $this->resolver)->assertManualDocumentPayment(
            $validator,
            isset($input['order_id']) ? (int) $input['order_id'] : null,
            isset($input['source_type']) ? (string) $input['source_type'] : null,
            isset($input['source_id']) ? (int) $input['source_id'] : null,
            $input['client_balance_id'] ?? null,
            filter_var($input['is_debt'] ?? false, FILTER_VALIDATE_BOOLEAN),
            isset($input['category_id']) ? (int) $input['category_id'] : null,
            isset($input['company_id']) ? (int) $input['company_id'] : $this->company->id,
        );

        return $validator;
    }

    private function seedReceiptCategoryBindings(): void
    {
        TransactionCategoryBinding::query()->updateOrCreate(
            [
                'company_id' => $this->company->id,
                'binding_key' => TransactionCategoryBindingKeys::WAREHOUSE_RECEIPT,
            ],
            ['transaction_category_id' => $this->goodsCategoryId],
        );
        TransactionCategoryBinding::query()->updateOrCreate(
            [
                'company_id' => $this->company->id,
                'binding_key' => TransactionCategoryBindingKeys::PRESET_WAREHOUSE_RECEIPT_DELIVERY_EXPENSE,
            ],
            ['transaction_category_id' => $this->deliveryCategoryId],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'creator_id' => $this->user->id,
            'status_id' => OrderStatus::factory()->create()->id,
        ], $overrides));
    }

    private function createBalance(): ClientBalance
    {
        return ClientBalance::query()->create([
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
            'type' => 1,
            'balance' => 0,
            'is_default' => true,
        ]);
    }

    private function createAlternateClientBalance(): ClientBalance
    {
        return ClientBalance::query()->create([
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
            'type' => 1,
            'balance' => 0,
        ]);
    }
}
