{{--
    e-Skool icon for every browser:
    - ICO for older browsers and Windows (listed first, with sizes, so modern browsers still pick the SVG),
    - SVG for Chrome, Edge, Firefox and Safari,
    - apple touch icon for iPhone and iPad home screens,
    - web app manifest with 192 and 512 px icons for Android home screens.
--}}
<link rel="icon" href="{{ asset('favicon-eskool.ico') }}" sizes="32x32" />
<link rel="icon" href="{{ asset('favicon-eskool.svg') }}" type="image/svg+xml" />
<link rel="apple-touch-icon" href="{{ asset('favicon-eskool-apple-touch.png') }}" />
<link rel="manifest" href="{{ asset('site.webmanifest') }}" />
