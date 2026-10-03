import type { InjectionKey, Ref } from "vue"

export type TabsVariant = "segmented" | "line"

export const TABS_VARIANT_KEY: InjectionKey<Ref<TabsVariant>> = Symbol("tabs-variant")
