import { IconLayoutGrid, IconUser } from '@tabler/icons-vue';
import type { Component } from 'vue';

import type { TemplateVisibility } from '@/types/template';

export const templateVisibilityIcons: Record<TemplateVisibility, Component> = {
    personal: IconUser,
    team: IconLayoutGrid,
};
