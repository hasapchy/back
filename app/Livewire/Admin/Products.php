<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Product;
use App\Models\ProductPrice;

use App\Models\Category;
use Livewire\WithFileUploads;
use Livewire\TemporaryUploadedFile;
use Illuminate\Http\UploadedFile;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class Products extends Component
{
    use WithPagination, WithFileUploads;

    public $name, $description, $sku, $stock_quantity, $status = true, $productId;
    public $image, $retail_price, $wholesale_price, $purchase_price, $barcode, $categoryId;
    public $showForm = false, $showCategoryForm = false, $showConfirmationModal = false;
    public $categoryName, $users = [], $allUsers, $parentCategoryId;
    public $columns = ['thumbnail', 'name', 'sku', 'stock_quantity', 'description', 'barcode'];
    public $stocks = [], $isDirty = false, $history = [], $searchTerm, $type = 1;

    protected $listeners = ['confirmClose'];

    public function mount()
    {
        $this->searchTerm = request('search', '');

        $this->allUsers = User::all();
    }

    public function render()
    {
        $products = strlen($this->searchTerm) < 3 && strlen($this->searchTerm) > 0
            ? Product::where('type', 1)->paginate(10)
            : Product::where('type', 1)->where('name', 'like', '%' . $this->searchTerm . '%')->paginate(10);

        return view('livewire.admin.products', [
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
        $this->productId = null;
        $this->name = '';
        $this->description = '';
        $this->sku = '';
        $this->image = null;
        $this->barcode = null;
        $this->categoryId = null;
        $this->retail_price = null;
        $this->wholesale_price = null;
        $this->purchase_price = null;
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
            'purchase_price' => 'nullable|numeric|min:0',
        ];

        if ($this->image instanceof TemporaryUploadedFile) {
            $rules['image'] = 'file|mimes:jpeg,png,jpg,gif|max:2048';
        } else {
            $rules['image'] = 'nullable';
        }

        $this->validate($rules);

        if ($this->productId) {
            $existingProduct = Product::find($this->productId);
            $oldImagePath = $existingProduct->image ?? null;
        } else {
            $oldImagePath = null;
        }

        if ($this->image instanceof UploadedFile) {
            $photoPath = $this->image->store('products', 'public');
        } elseif ($this->image === null) {
            $photoPath = null;
        } else {
            $photoPath = $oldImagePath;
        }

        $product = Product::updateOrCreate(
            ['id' => $this->productId],
            [
                'name' => $this->name,
                'category_id' => $this->categoryId,
                'description' => $this->description,
                'sku' => $this->sku,
                'stock_quantity' => 0,
                'status_id' => $this->status ? 1 : 0,
                'image' => $photoPath,
                'barcode' => $this->barcode,
                'type' => $this->type,
            ]
        );

        ProductPrice::updateOrCreate(
            ['product_id' => $product->id],
            [
                'retail_price' => $this->retail_price ?? 0.0,
                'wholesale_price' => $this->wholesale_price ?? 0.0,
                'purchase_price' => $this->purchase_price ?? 0.0,
            ]
        );

        session()->flash('success', $this->productId ? 'Товар успешно обновлен.' : 'Товар успешно добавлен.');

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
        $this->stock_quantity = $product->stock_quantity;
        $this->showForm = true;
        $this->image = $product->image;
        $this->barcode = $product->barcode;
        $this->categoryId = $product->category_id;

        $price = ProductPrice::where('product_id', $product->id)->first();
        if ($price) {
            $this->retail_price = $price->retail_price;
            $this->wholesale_price = $price->wholesale_price;
            $this->purchase_price = $price->purchase_price;
        }

        $this->stocks = WarehouseStock::where('product_id', $id)->with('warehouse')->get();
        $this->history = collect(array_merge(
            $product->receiptProducts->map(function ($receiptProduct) {
                return [
                    'type' => 'Оприходование',
                    'date' => $receiptProduct->receipt->created_at->format('Y-m-d'),
                    'quantity' => $receiptProduct->quantity,
                    'note' => $receiptProduct->receipt->note,
                    'warehouse' => $receiptProduct->receipt->warehouse->name,
                ];
            })->toArray(),
            $product->writeOffProducts->map(function ($writeOffProduct) {
                return [
                    'type' => 'Списание',
                    'date' => $writeOffProduct->writeOff->created_at->format('Y-m-d'),
                    'quantity' => $writeOffProduct->quantity,
                    'note' => $writeOffProduct->writeOff->note,
                    'warehouse' => $writeOffProduct->writeOff->warehouse->name,
                ];
            })->toArray(),
            $product->movementProducts->map(function ($movementProduct) {
                $fromWarehouseName =  $movementProduct->movement->warehouseFrom->name;
                $toWarehouseName = $movementProduct->movement->warehouseTo->name;
                return [
                    'type' => 'Перемещение',
                    'date' => $movementProduct->movement->created_at->format('Y-m-d'),
                    'quantity' => $movementProduct->quantity,
                    'warehouse' => $movementProduct->movement->warehouse . ' ' . $fromWarehouseName . ' -> ' . $toWarehouseName . '',
                    'note' => $movementProduct->movement->note,
                ];
            })->toArray(),
            $product->salesProducts->map(function ($salesProduct) {
                $sale = $salesProduct->sale;
                return [
                    'type' => 'Продажа',
                    'date' => $sale ? $sale->created_at->format('Y-m-d') : '-',
                    'quantity' => $salesProduct->quantity,
                    'warehouse' => $sale ? $sale->warehouse->name : '-',
                    'note' => $sale ? $sale->note : '',
                ];
            })->toArray()
        ))->sortByDesc('date')->toArray();
        // $this->isDirty = false;
    }


    public function delete($id)
    {
        Product::findOrFail($id)->delete();
        // $this->dispatch('deleted');
        $this->closeForm();
        session()->flash('success', 'Товар успешно удален.');
    }

    public function removeImage()
    {
        $this->image = null;
    }

    public function generateBarcode()
    {
        $ean = substr(str_pad(rand(1, 999999999999), 12, '0', STR_PAD_LEFT), 0, 12);
        $checksum = $this->calculateEAN13Checksum($ean);
        $this->barcode = $ean . $checksum;
        session()->flash('success', 'Штрих-код успешно сгенерирован.');
    }

    private function calculateEAN13Checksum($ean)
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ($i % 2 === 0 ? 1 : 3) * $ean[$i];
        }
        return (10 - ($sum % 10)) % 10;
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
