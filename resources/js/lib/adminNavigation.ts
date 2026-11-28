import {
    IconAlertTriangle,
    IconChartBar,
    IconChartDots,
    IconChartFunnel,
    IconCreditCard,
    IconDiscount,
    IconGauge,
    IconHeartbeat,
    IconLayoutDashboard,
    IconUsers,
} from '@tabler/icons-vue';
import type { Component } from 'vue';

import { dashboard } from '@/routes/admin';
import { index as insightsIndex } from '@/routes/admin/billing/insights';
import { index as offersIndex } from '@/routes/admin/billing/offers';
import { index as subscriptionsIndex } from '@/routes/admin/billing/subscriptions';
import { index as networksIndex } from '@/routes/admin/networks';
import { index as adminPostsIndex } from '@/routes/admin/posts';
import { index as failedPostsIndex } from '@/routes/admin/posts/failed';
import { index as quotasIndex } from '@/routes/admin/quotas';
import { index as usersIndex } from '@/routes/admin/users';
import { index as userInsightsIndex } from '@/routes/admin/users/insights';

interface AdminNavItem {
    name: string;
    title: string;
    href: string;
    icon: Component;
    exact?: boolean;
    /** Pages under this one that have their own item, so this one is not lit on them. */
    exclude?: string[];
}

interface AdminNavGroup {
    key: string;
    label: string;
    items: AdminNavItem[];
}

/** The backoffice's pages, as the sidebar lists them and the page header takes its icon from. */
export const ADMIN_NAV_GROUPS: AdminNavGroup[] = [
    {
        key: 'overview',
        label: 'Overview',
        items: [
            {
                name: 'dashboard',
                title: 'Dashboard',
                href: dashboard.url(),
                icon: IconLayoutDashboard,
                exact: true,
            },
        ],
    },
    {
        key: 'people',
        label: 'People',
        items: [
            {
                name: 'users',
                title: 'Users',
                href: usersIndex.url(),
                icon: IconUsers,
                exclude: [userInsightsIndex.url()],
            },
            {
                name: 'users-insights',
                title: 'Insights',
                href: userInsightsIndex.url(),
                icon: IconChartDots,
            },
        ],
    },
    {
        key: 'billing',
        label: 'Billing',
        items: [
            {
                name: 'insights',
                title: 'Insights',
                href: insightsIndex.url(),
                icon: IconChartFunnel,
            },
            {
                name: 'subscriptions',
                title: 'Subscriptions',
                href: subscriptionsIndex.url(),
                icon: IconCreditCard,
            },
            {
                name: 'offers',
                title: 'Offers',
                href: offersIndex.url(),
                icon: IconDiscount,
            },
        ],
    },
    {
        key: 'publishing',
        label: 'Publishing',
        items: [
            {
                name: 'posts',
                title: 'Posts',
                href: adminPostsIndex.url(),
                icon: IconChartBar,
                exact: true,
            },
            {
                name: 'failed-posts',
                title: 'Failed posts',
                href: failedPostsIndex.url(),
                icon: IconAlertTriangle,
            },
            {
                name: 'networks',
                title: 'Networks',
                href: networksIndex.url(),
                icon: IconHeartbeat,
            },
        ],
    },
    {
        key: 'usage',
        label: 'Usage',
        items: [
            {
                name: 'quotas',
                title: 'Quotas and spend',
                href: quotasIndex.url(),
                icon: IconGauge,
            },
        ],
    },
];
