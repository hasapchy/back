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

class Services extends Component
{
    use WithPagination;
    use WithFileUploads;

    public $name, $description, $sku, $articul, $status = true, $productId, $images = [], $newImages = [], $retail_price, $wholesale_price, $purchase_price, $category_id;
    public $defaultCurrencyId;
    public $showForm = false;
    public $showCategoryForm = false;
    public $showConfirmationModal = false;
    public $categoryName;
    public $parentCategoryId;
    public $columns = [
        'name',
        'sku'
    ];

    public $isDirty = false; 
    public $searchTerm;
    public $type = 0;

    protected $listeners = ['editProduct', 'confirmClose'];

    public function mount()
    {
        $this->searchTerm = request('search', '');
        $defaultCurrency = Currency::where('is_default', true)->first();
        $this->defaultCurrencyId = $defaultCurrency ? $defaultCurrency->id : null;
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
        $this->images = [];
        $this->category_id = null;
        $this->retail_price = null;
        $this->wholesale_price = null;
        $this->purchase_price = null;
        $this->showForm = false;
        $this->isDirty = false; // Reset dirty status
    }

    public function openForm()
    {
        $this->reset();
        $this->showForm = true;
        $this->isDirty = false; // Reset dirty status when opening form
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
            $this->isDirty = false; // Reset dirty status
            $this->showForm = false; // Ensure the form is hidden
        }
        $this->showConfirmationModal = false;
    }

    public function updated($propertyName)
    {
        // Whenever any bound property changes, mark the form as dirty
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
        $this->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'sku' => 'required|string|unique:products,sku,' . ($this->productId ?? 'NULL'),
            'articul' => 'nullable|string|max:255',
            'newImages.*' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:2048',
            'retail_price' => 'nullable|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
        ]);

        $photoPaths = $this->images;

        foreach ($this->newImages as $image) {
            if ($image instanceof UploadedFile) {
                $photoPaths[] = $image->store('products', 'public');
            }
        }

        try {

            $product = Product::updateOrCreate(
                ['id' => $this->productId],
                [
                    'name' => $this->name,
                    'category_id' => $this->category_id,
                    'description' => $this->description,
                    'sku' => $this->sku,
                    'articul' => $this->articul,
                    'status_id' => $this->status ? 1 : 0,
                    'images' => json_encode($photoPaths),
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
            $this->isDirty = false; // Reset dirty status after saving
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
        $this->articul = $product->articul;
        $this->showForm = true;
        $this->images = $product->images ? json_decode($product->images, true) : [];
        $this->category_id = $product->category_id;

        $price = ProductPrice::where('product_id', $product->id)->first();
        if ($price) {
            $this->retail_price = $price->retail_price;
            $this->wholesale_price = $price->wholesale_price;
            $this->purchase_price = $price->purchase_price;
        }

        $this->isDirty = false; 
    }

    public function update()
    {
        $this->validate();

        $product = Product::findOrFail($this->productId);

        $product->update([
            'name' => $this->name,
            'description' => $this->description,
            'sku' => $this->sku,
            'articul' => $this->articul,

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

    public function removeImage($index)
    {
        unset($this->images[$index]);
        $this->images = array_values($this->images); 
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

    public function render()
    {
        if (strlen($this->searchTerm) < 3 && strlen($this->searchTerm) > 0) {
            $products = Product::where('type', 0)->paginate(10);
        } else {
            $products = Product::where('type', 0)
                ->where(function ($query) {
                    $query->where('name', 'like', '%' . $this->searchTerm . '%');
                })
                ->paginate(10);
        }

        return view('livewire.admin.services', [
            'products' => $products,
            'categories' => Category::all(),
        ]);
    }
}
