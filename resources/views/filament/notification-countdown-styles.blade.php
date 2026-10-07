{{-- Shows a bar that runs down while a notification is on screen; persistent notifications have no bar. --}}
<style>
    .fi-no-notification:not(.fi-inline) {
        position: relative;
    }

    .fi-no-notification:not(.fi-inline):not([x-data*="persistent"])::after {
        content: '';
        position: absolute;
        inset: auto 0 0 0;
        height: 3px;
        background-color: var(--color-500, var(--gray-400));
        opacity: 0.8;
        transform-origin: left;
        animation: notification-countdown {{ $duration }}ms linear forwards;
    }

    @keyframes notification-countdown {
        from {
            transform: scaleX(1);
        }

        to {
            transform: scaleX(0);
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .fi-no-notification:not(.fi-inline):not([x-data*="persistent"])::after {
            animation-timing-function: steps(10, end);
        }
    }
</style>
