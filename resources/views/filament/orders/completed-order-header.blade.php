<x-filament-panels::header
    :actions="$actions"
    :actions-alignment="$actionsAlignment"
    :breadcrumbs="$breadcrumbs"
    :heading="$heading"
    :subheading="$subheading"
    class="completed-order-header"
/>

<style>
    .fi-header.completed-order-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .completed-order-header .fi-header-actions-ctn {
        width: 100%;
    }

    .completed-order-header .fi-header-actions-ctn > .fi-ac {
        justify-content: flex-start;
        max-width: 100%;
    }
</style>
