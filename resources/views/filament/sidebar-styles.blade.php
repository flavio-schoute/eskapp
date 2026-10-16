{{-- Shows an "opens in a new tab" icon after navigation items that link to another site, such as Finance. --}}
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
</style>
