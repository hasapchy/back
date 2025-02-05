<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Currency;
use App\Models\Category;
use Livewire\WithFileUploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use App\Models\WarehouseStock;

class Products extends Component
{
    use WithPagination;
    use WithFileUploads;

    public $name, $description, $sku, $stock_quantity, $status = true, $productId;
    public $image;
    public $retail_price, $wholesale_price, $purchase_price, $barcode, $category_id;
    public $defaultCurrencyId;
    public $showForm = false;
    public $showCategoryForm = false;
    public $showConfirmationModal = false;
    public $categoryName;
    public $parentCategoryId;
    public $columns = [
        'thumbnail',
        'name',
        'sku',
        'stock_quantity',
        'description',
        'barcode'
    ];

    public $stocks = [];
    public $isDirty = false; // Track if form fields were changed
    public $history = [];
    public $searchTerm;
    public $type = 1;

    protected $listeners = ['editProduct', 'confirmClose'];

    public function mount()
    {
        $this->searchTerm = request('search', '');
        $defaultCurrency = Currency::where('is_default', true)->first();
        $this->defaultCurrencyId = $defaultCurrency ? $defaultCurrency->id : null;
    }

    public function render()
    {
        if (strlen($this->searchTerm) < 3 && strlen($this->searchTerm) > 0) {
            $products = Product::where('type', 1)->paginate(10);
        } else {
            $products = Product::where('type', 1)
                ->where('name', 'like', '%' . $this->searchTerm . '%')
                ->paginate(10);
        }

        return view('livewire.admin.products', [
            'products' => $products,
            'categories' => Category::all(),

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
        ]);

        session()->flash('success', 'Категория успешно добавлена.');
        $this->resetCategoryForm();
        $this->dispatch('updated');
    }

    public function resetForm()
    {
        $this->productId = null;
        $this->name = '';
        $this->description = '';
        $this->sku = '';
        $this->image = null;
        $this->barcode = null;
        $this->category_id = null;
        $this->retail_price = null;
        $this->wholesale_price = null;
        $this->purchase_price = null;
        $this->showForm = false;
        $this->isDirty = false;
    }

    public function openForm()
    {
        $this->reset();
        $this->showForm = true;
        $this->isDirty = false;
    }

    public function closeForm()
    {
        if ($this->isDirty) {
            $this->showConfirmationModal = true;
        } else {
            $this->resetForm();
        }
    }

    public function closeModal($confirm = false)
    {
        if ($confirm) {
            $this->resetForm();
        }
        $this->showConfirmationModal = false;
    }

    public function confirmClose($confirm = false)
    {
        if ($confirm) {
            $this->resetForm();
            $this->isDirty = false;
            $this->showForm = false; 
        }
        $this->showConfirmationModal = false;
    }

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }

    public function updatedSearchTerm()
    {
        if (strlen($this->searchTerm) < 3 && strlen($this->searchTerm) > 0) {
            session()->flash('error', 'Поиск должен содержать не менее 3 символов.');
            $this->resetPage();
        } else {
            session()->forget('error');
            $this->resetPage();
        }
    }

    public function saveProduct()
    {
        $rules = [
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'sku' => 'required|string|unique:products,sku,' . ($this->productId ?? 'NULL'),
            // Если новое изображение загружено, то проверяем как файл, иначе пропускаем проверку:
            'retail_price' => 'nullable|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
        ];
    
        if ($this->image instanceof \Livewire\TemporaryUploadedFile) {
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
        try {
            $product = Product::updateOrCreate(
                ['id' => $this->productId],
                [
                    'name' => $this->name,
                    'category_id' => $this->category_id,
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
                    'currency_id' => $this->defaultCurrencyId ?? 1, 
                ]
            );

            session()->flash('success', $this->productId ? 'Товар успешно обновлен.' : 'Товар успешно добавлен.');
            $this->dispatch('updated');
            $this->resetForm();
            $this->isDirty = false; 
        } catch (QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                session()->flash('error', 'Штрих-код уже существует. Пожалуйста, используйте другой.');
            } else {
                session()->flash('error', 'Произошла ошибка при сохранении товара: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            session()->flash('error', 'Произошла ошибка при сохранении товара: ' . $e->getMessage());
        }
    }

    public function editProduct($id)
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
        $this->category_id = $product->category_id;

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
                    'note' => $movementProduct->movement->note . ' (' . $fromWarehouseName . ' -> ' . $toWarehouseName . ')',
                ];
            })->toArray(),
            $product->salesProducts->map(function ($salesProduct) {
                return [
                    'type' => 'Продажа',
                    // 'date' => $salesProduct->sale->created_at->format('Y-m-d'),
                    'date' => now(),
                    'quantity' => $salesProduct->quantity,
                    // 'note' => $salesProduct->sale->note,
                    'note'=> '',
                ];
            })->toArray()
        ))->sortByDesc('date')->toArray();
        $this->isDirty = false; // Reset dirty status when editing
    }

    public function update()
    {
        $this->validate();

        $product = Product::findOrFail($this->productId);

        $product->update([
            'name' => $this->name,
            'description' => $this->description,
            'sku' => $this->sku,
            'stock_quantity' => $this->stock_quantity,
        ]);

        session()->flash('success', 'Товар успешно обновлен.');

        $this->reset();
    }

    public function deleteProduct($id)
    {
        Product::findOrFail($id)->delete();
        $this->dispatch('deleted');
        $this->showForm = false;
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
        return $ean . $checksum;
    }

    public function generateBarcodeManually()
    {
        $this->barcode = $this->generateBarcode();
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
        $this->showCategoryForm = false;
    }
}
