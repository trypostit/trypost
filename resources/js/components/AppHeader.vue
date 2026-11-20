<template>
    <header
        class="flex shrink-0 items-center justify-between gap-2 bg-card px-4 pt-4 md:px-8 md:pt-6"
        data-testid="app-header"
    >
        <div class="flex h-12 min-w-0 flex-1 items-center gap-2">
            <slot name="left" />
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <div id="app-header-actions" class="contents" />
            <slot name="right" />
        </div>
    </header>
</template>
