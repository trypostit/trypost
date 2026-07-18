<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconBolt, IconDeviceDesktop, IconLifebuoy } from '@tabler/icons-vue';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import {
    ConnectionMode,
    MarkerType,
    VueFlow,
    useVueFlow,
    type Connection,
    type Edge,
    type Node,
    type XYPosition,
} from '@vue-flow/core';
import { trans } from 'laravel-vue-i18n';
import { computed, markRaw, provide, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';


import AutomationConnectionLine from '@/components/automations/AutomationConnectionLine.vue';
import AutomationHeader from '@/components/automations/AutomationHeader.vue';
import AutomationMobileBackHeader from '@/components/automations/AutomationMobileBackHeader.vue';
import ConditionNodeConfig from '@/components/automations/config/ConditionNodeConfig.vue';
import DelayNodeConfig from '@/components/automations/config/DelayNodeConfig.vue';
import EndNodeConfig from '@/components/automations/config/EndNodeConfig.vue';
import FetchRssNodeConfig from '@/components/automations/config/FetchRssNodeConfig.vue';
import GenerateNodeConfig from '@/components/automations/config/GenerateNodeConfig.vue';
import HttpRequestNodeConfig from '@/components/automations/config/HttpRequestNodeConfig.vue';
import PublishNodeConfig from '@/components/automations/config/PublishNodeConfig.vue';
import TriggerNodeConfig from '@/components/automations/config/TriggerNodeConfig.vue';
import WebhookNodeConfig from '@/components/automations/config/WebhookNodeConfig.vue';
import { firstConfigIssue } from '@/components/automations/config-validation';
import EditorSidebar from '@/components/automations/EditorSidebar.vue';
import ConditionNode from '@/components/automations/nodes/ConditionNode.vue';
import DelayNode from '@/components/automations/nodes/DelayNode.vue';
import EndNode from '@/components/automations/nodes/EndNode.vue';
import FetchRssNode from '@/components/automations/nodes/FetchRssNode.vue';
import GenerateNode from '@/components/automations/nodes/GenerateNode.vue';
import HttpRequestNode from '@/components/automations/nodes/HttpRequestNode.vue';
import PublishNode from '@/components/automations/nodes/PublishNode.vue';
import TriggerNode from '@/components/automations/nodes/TriggerNode.vue';
import WebhookNode from '@/components/automations/nodes/WebhookNode.vue';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { AddEdgeCommand } from '@/composables/history/commands/AddEdgeCommand';
import { AddNodeCommand } from '@/composables/history/commands/AddNodeCommand';
import { MoveNodeCommand } from '@/composables/history/commands/MoveNodeCommand';
import { RemoveEdgeCommand } from '@/composables/history/commands/RemoveEdgeCommand';
import { RemoveNodeCommand } from '@/composables/history/commands/RemoveNodeCommand';
import { UpdateNodeDataCommand } from '@/composables/history/commands/UpdateNodeDataCommand';
import { useHistory } from '@/composables/history/useHistory';
import { buildExpressionCatalog } from '@/composables/useExpressionCompletions';
import { usePageErrors } from '@/composables/usePageErrors';
import { useShortcut } from '@/composables/useShortcut';
import AppLayout from '@/layouts/AppLayout.vue';
import { update as updateAutomation } from '@/routes/app/automations';
import { AuthType } from '@/types/automation/auth-type';
import type { Automation, AutomationVariable } from '@/types/automation/automation';
import { ConditionOperator } from '@/types/automation/condition-operator';
import { DelayUnit } from '@/types/automation/delay-unit';
import { HttpMethod } from '@/types/automation/http-method';
import { NodeType } from '@/types/automation/node-type';
import { PublishMode } from '@/types/automation/publish-mode';
import type { RawConnection } from '@/types/automation/raw-connection';
import { ScheduleField } from '@/types/automation/schedule-field';
import { TriggerType } from '@/types/automation/trigger-type';

const props = defineProps<{ automation: Automation }>();

const nodeTypes = {
    [NodeType.Trigger]: markRaw(TriggerNode),
    [NodeType.Generate]: markRaw(GenerateNode),
    [NodeType.Delay]: markRaw(DelayNode),
    [NodeType.Condition]: markRaw(ConditionNode),
    [NodeType.Publish]: markRaw(PublishNode),
    [NodeType.Webhook]: markRaw(WebhookNode),
    [NodeType.End]: markRaw(EndNode),
    [NodeType.FetchRss]: markRaw(FetchRssNode),
    [NodeType.HttpRequest]: markRaw(HttpRequestNode),
};

const configByType: Record<string, unknown> = {
    [NodeType.Trigger]: TriggerNodeConfig,
    [NodeType.Generate]: GenerateNodeConfig,
    [NodeType.Delay]: DelayNodeConfig,
    [NodeType.Condition]: ConditionNodeConfig,
    [NodeType.Publish]: PublishNodeConfig,
    [NodeType.Webhook]: WebhookNodeConfig,
    [NodeType.End]: EndNodeConfig,
    [NodeType.FetchRss]: FetchRssNodeConfig,
    [NodeType.HttpRequest]: HttpRequestNodeConfig,
};

const hydrateEdges = (list: RawConnection[]): Edge[] =>
    list.map((e) => ({
        id: e.id,
        source: e.source,
        target: e.target,
        sourceHandle: e.source_handle ?? e.sourceHandle ?? undefined,
        targetHandle: e.target_handle ?? e.targetHandle ?? undefined,
    }));

const nodes = ref<Node[]>(props.automation.nodes ?? []);
const edges = ref<Edge[]>(hydrateEdges(props.automation.connections ?? []));
const selectedNodeId = ref<string | null>(null);
const selectedEdgeId = ref<string | null>(null);
const variables = ref<AutomationVariable[]>(props.automation.variables ?? []);

const configIssue = computed(() => firstConfigIssue(nodes.value));

watch(
    () => [props.automation.nodes, props.automation.connections] as const,
    ([newNodes, newEdges]) => {
        nodes.value = newNodes ?? [];
        edges.value = hydrateEdges(newEdges ?? []);
        // Server-canonical state arrived — any prior undo history points at
        // stale references, so reset it together with the in-flight config diff.
        configSnapshot = null;
        history.clear();
    },
);

watch(() => props.automation.variables, (newVariables) => {
    variables.value = newVariables ?? [];
});

const selectedNode = computed(() => nodes.value.find((n) => n.id === selectedNodeId.value));

// `{{ ... }}` suggestions available to the selected node's config editors —
// everything its upstream nodes provide, plus workflow variables and `now`.
// Injected by every CodeEditor inside this editor (see CodeEditor.vue).
provide(
    'automationExpressionCompletions',
    computed(() => buildExpressionCatalog(selectedNodeId.value, nodes.value, edges.value, variables.value)),
);
provide('automationId', props.automation.id);

// Toggled by an expandable CodeEditor (see CodeEditor.vue) to slide out the
// side-by-side editing panel, pushing the sidebar left to make room.
const expandedEditor = reactive({ active: false });
provide('automationExpandedEditor', expandedEditor);
const selectedConfigComponent = computed(() =>
    selectedNode.value ? configByType[selectedNode.value.type as string] : null,
);

// Backend returns validation errors with paths like `nodes.{index}.data.{field}`.
// We flatten them to `{ field: message }` for the currently-selected node so each
// config component can show the InputError inline without knowing its own index.
const pageErrors = usePageErrors();
const selectedNodeErrors = computed<Record<string, string>>(() => {
    if (!selectedNode.value) return {};
    const idx = nodes.value.findIndex((n) => n.id === selectedNode.value!.id);
    if (idx === -1) return {};
    const prefix = `nodes.${idx}.data.`;
    const out: Record<string, string> = {};
    for (const [key, message] of Object.entries(pageErrors.value)) {
        if (key.startsWith(prefix)) {
            out[key.slice(prefix.length)] = message;
        }
    }
    return out;
});

const history = useHistory();

const {
    onNodeClick,
    onEdgeClick,
    onPaneClick,
    onConnect,
    onNodeDragStart,
    onNodeDragStop,
    addEdges,
    screenToFlowCoordinate,
} = useVueFlow();

onNodeClick(({ node }) => {
    selectedNodeId.value = node.id;
    selectedEdgeId.value = null;
});

// Selecting a connection closes the node config and arms it for deletion via
// Backspace/Delete — without removing the nodes it links.
onEdgeClick(({ edge }) => {
    selectedEdgeId.value = edge.id;
    selectedNodeId.value = null;
});

onPaneClick(() => {
    selectedEdgeId.value = null;
});

onConnect((connection: Connection) => {
    const edge: Edge = {
        ...connection,
        id: `edge_${Date.now()}_${Math.floor(Math.random() * 1000)}`,
    } as Edge;
    addEdges([edge]);
    history.push(new AddEdgeCommand(edge, edges));
});

let dragStartPosition: XYPosition | null = null;
onNodeDragStart(({ node }) => {
    dragStartPosition = { ...node.position };
    // A click that moves more than Vue Flow's drag threshold (1px) registers as a
    // drag, not a click, so onNodeClick never fires. Select here too, otherwise
    // switching between nodes intermittently fails to open the grabbed node's config.
    selectedNodeId.value = node.id;
    selectedEdgeId.value = null;
});
onNodeDragStop(({ node }) => {
    if (!dragStartPosition) return;
    const oldPos = dragStartPosition;
    dragStartPosition = null;
    const newPos = { ...node.position };
    if (oldPos.x === newPos.x && oldPos.y === newPos.y) return;
    history.push(new MoveNodeCommand(node.id, oldPos, newPos, nodes));
});

// Snapshot of the selected node's data at the moment the config panel opens.
// When the user switches nodes (or closes the panel), we diff against the live
// data and push a single UpdateNodeDataCommand for the whole editing session —
// avoids one undo step per keystroke.
let configSnapshot: { nodeId: string; data: Record<string, unknown> } | null = null;

// JSON-based clone because Vue Flow wraps node.data in reactive proxies that
// `structuredClone` rejects. Node data is always JSON-serializable (it's what
// gets persisted), so the conversion is lossless for our shape.
const cloneNodeData = (data: unknown): Record<string, unknown> =>
    JSON.parse(JSON.stringify(data ?? {})) as Record<string, unknown>;

const commitConfigSnapshot = (): void => {
    if (!configSnapshot) return;
    const node = nodes.value.find((n) => n.id === configSnapshot!.nodeId);
    if (node) {
        const liveData = cloneNodeData(node.data);
        if (JSON.stringify(liveData) !== JSON.stringify(configSnapshot.data)) {
            history.push(
                new UpdateNodeDataCommand(configSnapshot.nodeId, configSnapshot.data, liveData, nodes),
            );
        }
    }
    configSnapshot = null;
};

watch(selectedNodeId, (newId, oldId) => {
    if (oldId) commitConfigSnapshot();
    if (newId) {
        const node = nodes.value.find((n) => n.id === newId);
        if (node) {
            configSnapshot = { nodeId: newId, data: cloneNodeData(node.data) };
        }
    }
});

const updateSelectedConfig = (newData: Record<string, unknown>) => {
    if (!selectedNode.value) return;
    const idx = nodes.value.findIndex((n) => n.id === selectedNode.value!.id);
    if (idx === -1) return;
    nodes.value[idx] = { ...nodes.value[idx], data: { ...nodes.value[idx].data, ...newData } };
};

const defaultConfigFor = (type: string): Record<string, unknown> => {
    switch (type) {
        case NodeType.Trigger: return {
            trigger_type: TriggerType.Schedule,
            cron: '0 9 * * *',
            schedule_field: ScheduleField.Days,
            schedule_days_interval: 1,
            schedule_hour: 9,
            schedule_minute: 0,
            schedule_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        };
        case NodeType.Generate: return { accounts: [], prompt_template: '', target_slide_count: 1 };
        case NodeType.Delay: return { duration: 1, unit: DelayUnit.Hours };
        case NodeType.Condition: return { field: '', operator: ConditionOperator.Contains, value: '' };
        case NodeType.Publish: return { mode: PublishMode.Now, scheduled_offset: 60 };
        case NodeType.Webhook: return { url: '', method: HttpMethod.Post, headers: {}, payload_template: '{}' };
        case NodeType.End: return { reason: '' };
        case NodeType.FetchRss: return { feed_url: '' };
        case NodeType.HttpRequest: return {
            url: '',
            method: HttpMethod.Get,
            auth_type: AuthType.None,
            headers: {},
            body_template: '',
            items_path: '',
            item_key_path: '',
            item_date_path: '',
        };
        default: return {};
    }
};

const createNodeAt = (type: string, position: { x: number; y: number }) => {
    const node: Node = {
        id: `node_${Date.now()}_${Math.floor(Math.random() * 1000)}`,
        type,
        position,
        data: defaultConfigFor(type),
    };
    nodes.value = [...nodes.value, node];
    history.push(new AddNodeCommand(node, nodes));
};

const onDragOver = (event: DragEvent) => {
    event.preventDefault();
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }
};

const onDrop = (event: DragEvent) => {
    event.preventDefault();
    const nodeType = event.dataTransfer?.getData('application/automation-node-type');
    if (!nodeType) return;
    const position = screenToFlowCoordinate({ x: event.clientX, y: event.clientY });
    createNodeAt(nodeType, position);
};

const deleteSelectedNode = () => {
    if (!selectedNode.value) return;
    // The trigger is the automation's single entry point — it can't be deleted.
    if (selectedNode.value.type === NodeType.Trigger) return;
    const node = selectedNode.value;
    const cascadedEdges = edges.value.filter((e) => e.source === node.id || e.target === node.id);

    history.startBulk();
    cascadedEdges.forEach((e) => history.push(new RemoveEdgeCommand(e, edges)));
    history.push(new RemoveNodeCommand(node, nodes));
    history.endBulk();

    selectedNodeId.value = null;
    edges.value = edges.value.filter((e) => e.source !== node.id && e.target !== node.id);
    nodes.value = nodes.value.filter((n) => n.id !== node.id);
};

const deleteSelectedEdge = () => {
    if (!selectedEdgeId.value) return;
    const edge = edges.value.find((e) => e.id === selectedEdgeId.value);
    if (!edge) return;

    history.push(new RemoveEdgeCommand(edge, edges));
    edges.value = edges.value.filter((e) => e.id !== edge.id);
    selectedEdgeId.value = null;
};

const isSaving = ref(false);
const activeTab = ref('build');

const sanitizeNodes = (list: Node[]) =>
    list.map((n) => ({
        id: n.id,
        type: n.type,
        position: { x: n.position.x, y: n.position.y },
        data: n.data ?? {},
    }));

const sanitizeEdges = (list: Edge[]) =>
    list.map((e) => {
        const edge: Record<string, unknown> = {
            id: e.id,
            source: e.source,
            target: e.target,
        };
        if (e.sourceHandle) edge.source_handle = e.sourceHandle;
        if (e.targetHandle) edge.target_handle = e.targetHandle;
        return edge;
    });

const save = (): Promise<boolean> =>
    new Promise((resolve) => {
        if (isSaving.value) return resolve(false);
        isSaving.value = true;
        router.put(
            updateAutomation.url(props.automation.id),
            {
                nodes: sanitizeNodes(nodes.value),
                connections: sanitizeEdges(edges.value),
                variables: variables.value.filter((variable) => variable.key.trim() !== ''),
            },
            {
                preserveScroll: true,
                onFinish: () => { isSaving.value = false; },
                onSuccess: () => {
                    toast.success(trans('automations.form.save_success'));
                    resolve(true);
                },
                onError: (errors: Record<string, string>) => {
                    // Field errors render inline via the node config's <InputError>,
                    // so we just reveal the node carrying the first one. Only general
                    // failures (no field to attach to) fall back to a toast.
                    const erroredNodeIndex = Object.keys(errors).find((key) => key.startsWith('nodes.'))?.split('.')[1];
                    if (erroredNodeIndex !== undefined) {
                        selectedNodeId.value = nodes.value[Number(erroredNodeIndex)]?.id ?? selectedNodeId.value;
                    } else {
                        toast.error(errors.message ?? trans('automations.form.save_error_fallback'));
                    }
                    resolve(false);
                },
            },
        );
    });

const closePanel = () => {
    selectedNodeId.value = null;
};

useShortcut('mod+s', save);
useShortcut('mod+z', () => {
    commitConfigSnapshot();
    history.undo();
});
useShortcut('mod+shift+z', () => {
    commitConfigSnapshot();
    history.redo();
});
useShortcut('backspace', () => {
    if (selectedNode.value) deleteSelectedNode();
    else if (selectedEdgeId.value) deleteSelectedEdge();
}, { ignoreOnInput: true });
useShortcut('delete', () => {
    if (selectedNode.value) deleteSelectedNode();
    else if (selectedEdgeId.value) deleteSelectedEdge();
}, { ignoreOnInput: true });

const defaultEdgeOptions = {
    type: 'smoothstep',
    animated: false,
    style: { strokeWidth: 3 },
    pathOptions: {
        borderRadius: 32,
        offset: 24,
    },
    markerEnd: {
        type: MarkerType.ArrowClosed,
        width: 18,
        height: 18,
        color: '#0a0a0a',
    },
};
</script>

<template>
    <Head :title="`Edit ${automation.name}`" />

    <AppLayout full-width>
        <div class="flex min-h-0 flex-1 flex-col bg-background">
            <AutomationMobileBackHeader />

            <AutomationHeader :automation="automation" current="workflow" class="hidden lg:block">
                <template #actions>
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button
                                    as="a"
                                    href="https://docs.trypost.it/knowledge-base/automations/introduction"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    variant="outline"
                                    size="icon-sm"
                                    class="group"
                                    :aria-label="$t('automations.actions.guide')"
                                >
                                    <IconLifebuoy class="size-4 transition-transform duration-500 group-hover:rotate-180" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>{{ $t('automations.actions.guide') }}</TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                    <Button size="sm" @click="save" :disabled="isSaving">{{ $t('automations.actions.save') }}</Button>
                </template>
            </AutomationHeader>

            <div class="hidden flex-1 overflow-hidden lg:flex">
                <main
                    class="automations-canvas-host relative flex-1"
                    @drop="onDrop"
                    @dragover="onDragOver"
                >
                    <VueFlow
                        v-model:nodes="nodes"
                        v-model:edges="edges"
                        :node-types="nodeTypes"
                        :default-edge-options="defaultEdgeOptions"
                        :connection-mode="ConnectionMode.Loose"
                        :delete-key-code="null"
                        :node-drag-threshold="5"
                        :snap-to-grid="true"
                        :snap-grid="[16, 16]"
                        fit-view-on-init
                        class="automations-canvas"
                    >
                        <Background
                            pattern-color="#8b919c"
                            :gap="16"
                            :size="1.4"
                        />
                        <Controls position="bottom-left" />
                        <template #connection-line="props">
                            <AutomationConnectionLine v-bind="props" />
                        </template>
                    </VueFlow>

                    <div
                        v-if="nodes.length === 0"
                        class="pointer-events-none absolute inset-0 flex items-center justify-center"
                    >
                        <div class="text-center text-muted-foreground">
                            <IconBolt :size="48" class="mx-auto mb-3 opacity-40" />
                            <p class="font-medium">{{ $t('automations.form.empty_canvas_title') }}</p>
                            <p class="mt-1 text-sm">{{ $t('automations.form.empty_canvas_description') }}</p>
                        </div>
                    </div>
                </main>

                <EditorSidebar
                    v-model:tab="activeTab"
                    v-model:variables="variables"
                    :automation-id="automation.id"
                    :before-run="save"
                    :config-issue="configIssue"
                    :editing="!!selectedNode"
                    :node-title="selectedNode ? $t(`automations.nodes.${selectedNode.type}`) : ''"
                    :deletable="selectedNode?.type !== NodeType.Trigger"
                    @back="closePanel"
                    @delete="deleteSelectedNode"
                >
                    <template v-if="selectedNode" #config>
                        <component
                            :is="selectedConfigComponent"
                            :key="selectedNode.id"
                            :data="selectedNode.data"
                            :errors="selectedNodeErrors"
                            @update="updateSelectedConfig"
                        />
                    </template>
                </EditorSidebar>

                <aside v-show="expandedEditor.active" class="flex w-[30rem] shrink-0 flex-col py-3 pr-3">
                    <div
                        id="automation-expanded-editor"
                        class="flex h-full min-h-0 flex-col overflow-hidden rounded-2xl border-2 border-foreground bg-card shadow-[3px_3px_0_var(--foreground)]"
                    />
                </aside>
            </div>

            <div
                data-testid="automation-mobile-notice"
                class="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center lg:hidden"
            >
                <div class="inline-flex size-14 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-200 shadow-2xs">
                    <IconDeviceDesktop class="size-7 text-foreground" stroke-width="2" />
                </div>
                <p class="max-w-sm text-sm text-foreground/70">{{ $t('automations.mobile_notice') }}</p>
            </div>
        </div>
    </AppLayout>
</template>

<style>
/* Canvas surface — n8n uses a near-white gray (#f5f5f5) as the canvas background.
   TryPost uses a warm cream (#faf8f5) for brand consistency. Dots use n8n's
   neutral-500 gray, drawn at 1px with 16px gap (n8n's GRID_SIZE) so they are
   crisp and clearly visible against either tint. */
.automations-canvas-host {
    background-color: var(--background);
}

.automations-canvas .vue-flow__background {
    background-color: transparent;
}

/* Controls — keep TryPost's ink-border, hard-shadow identity but adopt n8n's
   compact, square-icon layout (icons sit vertically on the bottom-left). */
.automations-canvas .vue-flow__controls {
    box-shadow: var(--shadow-sm);
    border: 2px solid var(--foreground);
    border-radius: 0.625rem;
    overflow: hidden;
    background: var(--card);
}

.automations-canvas .vue-flow__controls-button {
    background: var(--card);
    border-bottom: 1px solid color-mix(in srgb, var(--foreground) 10%, transparent);
    color: var(--foreground);
    width: 24px;
    height: 24px;
    transition: background-color 120ms ease;
}

.automations-canvas .vue-flow__controls-button:last-child {
    border-bottom: none;
}

.automations-canvas .vue-flow__controls-button:hover {
    background: var(--muted);
}

.automations-canvas .vue-flow__controls-button svg {
    fill: currentColor;
    max-width: 12px;
    max-height: 12px;
}

/* Edges — TryPost ink-on-cream. Default uses the foreground color at 55% so
   it reads as a confident line without being heavy. Hover/selected pop to full
   ink. The arrowhead marker (configured per edge in JS) inherits stroke color
   via `context-stroke`. */
.automations-canvas .vue-flow__edge-path {
    stroke: color-mix(in srgb, var(--foreground) 60%, transparent);
    stroke-width: 3;
    stroke-linecap: round;
    stroke-linejoin: round;
    transition: stroke 120ms ease;
}

.automations-canvas .vue-flow__edge:hover .vue-flow__edge-path,
.automations-canvas .vue-flow__edge.selected .vue-flow__edge-path,
.automations-canvas .vue-flow__edge:focus .vue-flow__edge-path {
    stroke: var(--foreground);
}

.automations-canvas .vue-flow__connection-path,
.automations-canvas .vue-flow__connectionline .vue-flow__edge-path {
    stroke: var(--foreground);
    stroke-width: 3;
    stroke-linecap: round;
    stroke-linejoin: round;
}


/* Handles — solid ink-bordered dots in the TryPost brutalist style. Offset by
   -2px on the active side compensates for the node's 2px border so the dot
   straddles the border (50% inside / 50% outside). */
.automations-canvas .vue-flow__handle {
    width: 14px;
    height: 14px;
    border-radius: 9999px;
    border: 2px solid var(--foreground);
    transition: transform 120ms ease;
}

/* Invisible halo expands the clickable / hoverable hit area without changing
   the visual dot size. Matches the cross cursor users see when starting a
   connection. */
.automations-canvas .vue-flow__handle::before {
    content: '';
    position: absolute;
    inset: -10px;
    border-radius: 9999px;
}

.automations-canvas .vue-flow__handle.vue-flow__handle-right {
    right: -2px;
}

.automations-canvas .vue-flow__handle.vue-flow__handle-left {
    left: -2px;
}

.automations-canvas .vue-flow__handle.vue-flow__handle-right:hover {
    transform: translate(50%, -50%) scale(1.5);
}

.automations-canvas .vue-flow__handle.vue-flow__handle-left:hover {
    transform: translate(-50%, -50%) scale(1.5);
}

.automations-canvas .vue-flow__handle.vue-flow__handle-top:hover {
    transform: translate(-50%, -50%) scale(1.5);
}

.automations-canvas .vue-flow__handle.vue-flow__handle-bottom:hover {
    transform: translate(-50%, 50%) scale(1.5);
}

/* Node selection ring — n8n uses a wide soft halo; we keep the ring crisp but
   tighter so it pairs with the ink-border identity. */
.automations-canvas .vue-flow__node.selected {
    z-index: 10;
}
</style>
