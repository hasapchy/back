<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Currency;
use App\Models\Category;
use Livewire\WithFileUploads;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class Services extends Component
{
    use WithPagination, WithFileUploads;

    public $name, $description, $sku,  $status = true, $productId;
    public $retail_price, $wholesale_price, $purchase_price, $categoryId;
    public $currencies = [], $currencyId, $showForm = false, $showCategoryForm = false, $showConfirmationModal = false;
    public $categoryName, $users = [], $allUsers, $parentCategoryId;
    public $columns = ['name', 'sku',  'description',];
    public $stocks = [], $isDirty = false, $history = [], $searchTerm, $type = 0;

    protected $listeners = ['confirmClose'];

    public function mount()
    {
        $this->searchTerm = request('search', '');
        $this->currencies = Currency::all();
        $this->allUsers = User::all();
    }

    public function render()
    {
        $products = strlen($this->searchTerm) < 3 && strlen($this->searchTerm) > 0
            ? Product::where('type', 0)->paginate(10)
            : Product::where('type', 0)->where('name', 'like', '%' . $this->searchTerm . '%')->paginate(10);

        return view('livewire.admin.services', [
            'products' => $products,
            'categories' => Category::whereJsonContains('users', (string) Auth::id())->get(),
        ]);
    }

    public function saveCategory()
    {
        $this->validate([
            'categoryName' => 'required|string|max:255',
            'parentCategoryId' => 'nullable|exists:categories,id',
        ]);

        Category::create([
            'name' => $this->categoryName,
            'parent_id' => $this->parentCategoryId,
            'user_id' => Auth::id(),
            'users' => $this->users,
        ]);

        session()->flash('success', 'Категория успешно добавлена.');
        $this->resetCategoryForm();
    }

    public function resetForm()
    {
        $this->reset('productId', 'name', 'description', 'sku',  'retail_price', 'wholesale_price', 'purchase_price', 'currencyId');
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm()
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function confirmClose($confirm = false)
    {
        if ($confirm) {
            $this->showForm = false;
        }
        $this->showConfirmationModal = false;
    }


    public function updatedSearchTerm()
    {
        if (strlen($this->searchTerm) < 3 && strlen($this->searchTerm) > 0) {
            session()->flash('error', 'Поиск должен содержать не менее 3 символов.');
        } else {
            session()->forget('error');
        }
    }

    public function save()
    {
        $rules = [
            'name' => 'required|string|max:255',
            'categoryId' => 'required|exists:categories,id',
            'sku' => 'required|string|unique:products,sku,' . $this->productId,
            'retail_price' => 'nullable|numeric|min:0,01',
            'wholesale_price' => 'nullable|numeric|min:0,01',
            'purchase_price' => 'nullable|numeric|min:0,01',
        ];

        $this->validate($rules);

        $product = Product::updateOrCreate(
            ['id' => $this->productId],
            [
                'name' => $this->name,
                'category_id' => $this->categoryId,
                'description' => $this->description,
                'sku' => $this->sku,
                'status_id' => $this->status ? 1 : 0,
                'type' => $this->type,
            ]
        );

        ProductPrice::updateOrCreate(
            ['product_id' => $product->id],
            [
                'retail_price' => $this->retail_price ?? 0.0,
                'wholesale_price' => $this->wholesale_price ?? 0.0,
                'purchase_price' => $this->purchase_price ?? 0.0,
                'currency_id' => $this->currencyId,
            ]
        );

        session()->flash('success', $this->productId ? 'Услуга успешно обновлена.' : 'Услуга успешно добавлена.');

        $this->closeForm();
        $this->dispatch('updated');
    }

    public function edit($id)
    {
        $product = Product::findOrFail($id);
        $this->productId = $product->id;
        $this->name = $product->name;
        $this->description = $product->description;
        $this->sku = $product->sku;
        $this->showForm = true;
        $this->categoryId  = $product->category_id;

        $price = ProductPrice::where('product_id', $product->id)->first();
        if ($price) {
            $this->retail_price = $price->retail_price;
            $this->wholesale_price = $price->wholesale_price;
            $this->purchase_price = $price->purchase_price;
            $this->currencyId = $price->currency_id;
        }

        $this->stocks = WarehouseStock::where('product_id', $id)->get();
        $this->history = collect(array_merge(

            $product->salesProducts->map(function ($salesProduct) {
                $sale = $salesProduct->sale;
                return [
                    'type' => 'Продажа',
                    'date' => $sale ? $sale->created_at->format('Y-m-d') : '-',
                    'note' => $sale ? $sale->note : '',
                ];
            })->toArray()
        ))->sortByDesc('date')->toArray();
        $this->showForm = true;
    }


    public function delete($id)
    {
        Product::findOrFail($id)->delete();
        $this->closeForm();
        session()->flash('success', 'услуга успешно удален.');
    }

    public function createCategory()
    {
        $this->resetCategoryForm();
        $this->showCategoryForm = true;
    }

    public function resetCategoryForm()
    {
        $this->categoryName = '';
        $this->parentCategoryId = null;
        $this->users = [];
        $this->showCategoryForm = false;
    }
}
