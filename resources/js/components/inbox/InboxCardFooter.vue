<template>
    <div
        class="m-2 flex min-h-14 flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl bg-muted p-3 [&_[data-variant=ghost]:hover]:bg-secondary"
        @click.stop
    >
        <div class="flex min-w-0 items-center gap-1 text-sm text-foreground">
            <slot name="meta" />
        </div>
        <div class="flex max-w-full shrink-0 flex-wrap items-center gap-1 max-sm:ms-auto">
            <slot />
        </div>
    </div>
</template>
