<div id="confirmationModal"
    class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 transition-opacity duration-500 {{ $showConfirmationModal ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}">
    <div class="bg-white w-2/3 p-6 rounded-lg shadow-lg">
        <h2 class="text-xl font-bold mb-4">Вы уверены, что хотите закрыть?</h2>
        <p>Все несохранённые данные будут потеряны.</p>
        <div class="mt-4 flex space-x-2">
            <button wire:click="confirmClose(true)" class="bg-red-500 text-white px-4 py-2 rounded">Да</button>
            <button wire:click="confirmClose(false)" class="bg-green-500 text-white px-4 py-2 rounded">Нет</button>
        </div>
    </div>
</div>
