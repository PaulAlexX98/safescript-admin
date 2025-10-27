<div class="flex justify-end gap-3">
    <x-filament::button type="submit" form="form" color="primary">
        Save changes
    </x-filament::button>

    <x-filament::button color="gray" tag="a" :href="url()->previous()">
        Cancel
    </x-filament::button>
</div>