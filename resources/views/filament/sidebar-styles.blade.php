{{-- Sidebar tweaks: an "opens in a new tab" icon after links to other sites (Finance), and the Settings group at the bottom. --}}
<style>
    .fi-sidebar-item-btn[target="_blank"] .fi-sidebar-item-label::after {
        content: '';
        display: inline-block;
        width: 0.9rem;
        height: 0.9rem;
        margin-inline-start: 0.375rem;
        vertical-align: -0.1rem;
        background-color: currentColor;
        opacity: 0.6;
        -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke-width='1.5' stroke='black'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25'/%3E%3C/svg%3E") center / contain no-repeat;
        mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke-width='1.5' stroke='black'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25'/%3E%3C/svg%3E") center / contain no-repeat;
    }

    .fi-sidebar-nav {
        display: flex;
        flex-direction: column;
    }

    .fi-sidebar-nav-groups {
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .fi-sidebar-group[data-group-label="Settings"] {
        margin-top: auto;
    }

    /* A clearly separate sidebar, like the Laravel starter kits: its own background and a border on the right. */
    @media (min-width: 1024px) {
        .fi-sidebar.fi-main-sidebar {
            background-color: #fff;
            border-inline-end: 1px solid var(--gray-200);
        }

        .dark .fi-sidebar.fi-main-sidebar {
            background-color: var(--gray-900);
            border-inline-end-color: rgb(255 255 255 / 0.1);
        }
    }

    /* The collapse button sits to the right of the logo; the sidebar collapses to the left with a smooth animation. */
    .fi-sidebar-header .fi-sidebar-header-logo-ctn {
        order: 1;
    }

    .fi-sidebar-header .fi-sidebar-close-collapse-sidebar-btn {
        order: 2;
    }

    @media (min-width: 1024px) {
        .fi-sidebar.fi-main-sidebar {
            transition: width 250ms ease-in-out !important;
            overflow-x: hidden;
        }

        .fi-sidebar.fi-main-sidebar .fi-sidebar-item-label,
        .fi-sidebar.fi-main-sidebar .fi-sidebar-group-label,
        .fi-sidebar.fi-main-sidebar .fi-logo {
            white-space: nowrap;
        }
    }

    /* On desktop the logo and the collapse button are in the top bar, above the sidebar: put the button at the
       right edge of the sidebar, after the logo. */
    @media (min-width: 1024px) {
        .fi-topbar-start {
            margin-inline-end: 0;
            transition: width 250ms ease-in-out;
        }

        .fi-topbar-start > a,
        .fi-topbar-start > .fi-logo {
            order: 1;
        }

        .fi-topbar-start .fi-topbar-collapse-sidebar-btn-ctn {
            order: 2;
        }

        .fi-body:has(.fi-main-sidebar.fi-sidebar-open) .fi-topbar-start {
            width: calc(var(--sidebar-width) - 2.25rem);
            justify-content: space-between;
            align-items: center;
        }

        .fi-topbar-start .fi-topbar-collapse-sidebar-btn-ctn {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            height: 100%;
        }

        .fi-topbar-start > a {
            display: flex;
            align-items: center;
        }

        .fi-body:has(.fi-main-sidebar.fi-sidebar-open) .fi-topbar-start .fi-logo {
            margin-inline-start: 0.5rem;
        }
    }

    /* The collapsed sidebar shows only the group icons, linking to each group's first item. */
    .fi-sidebar-group-dropdown-trigger-btn.fi-active {
        color: var(--primary-600);
        background-color: var(--gray-100);
    }

    .dark .fi-sidebar-group-dropdown-trigger-btn.fi-active {
        color: var(--primary-400);
        background-color: rgb(255 255 255 / 0.05);
    }

    .fi-sidebar-footer {
        border-top: 1px solid var(--gray-200);
    }

    .dark .fi-sidebar-footer {
        border-top-color: rgb(255 255 255 / 0.1);
    }
</style>
