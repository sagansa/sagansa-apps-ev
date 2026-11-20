<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- SEO Meta Tags -->
    <title>@yield('title', 'EV Charger - Find Electric Vehicle Charging Stations')</title>
    <meta name="description" content="@yield('description', 'Discover and locate electric vehicle charging stations across Indonesia. Find nearby EV chargers, compare providers, and plan your electric journey with our comprehensive EV charging map.')">
    <meta name="keywords" content="EV charger, electric vehicle charging, Indonesia EV charging, EV charging map, electric car charging stations">
    <meta name="author" content="Sagansa EV">
    <meta name="robots" content="index, follow">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="@yield('title', 'EV Charger - Find Electric Vehicle Charging Stations')">
    <meta property="og:description" content="@yield('description', 'Discover and locate electric vehicle charging stations across Indonesia. Find nearby EV chargers, compare providers, and plan your electric journey with our comprehensive EV charging map.')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="Sagansa EV">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('title', 'EV Charger - Find Electric Vehicle Charging Stations')">
    <meta name="twitter:description" content="@yield('description', 'Discover and locate electric vehicle charging stations across Indonesia. Find nearby EV chargers, compare providers, and plan your electric journey with our comprehensive EV charging map.')">

    <!-- Canonical URL -->
    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Favicon -->
    <link rel="icon" href="{{ asset('images/logo-files/favicon-32x32.png') }}" type="image/png">



    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @yield('additional_head')
    @livewireStyles
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js"></script>
    <style>
        .ev-site-nav {
            background: rgba(255, 255, 255, 0.92) !important;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 18px 48px -34px rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(16px);
        }

        .ev-site-nav a,
        .ev-site-nav button {
            color: #0f172a !important;
        }

        .ev-site-nav a:hover,
        .ev-site-nav button:hover,
        .ev-site-nav .nav-active {
            color: #2563eb !important;
        }

        .ev-site-nav .ev-nav-brand {
            color: #0f172a !important;
            letter-spacing: 0;
        }

        .ev-site-nav .ev-nav-logo {
            filter: drop-shadow(0 8px 16px rgba(37, 99, 235, 0.22));
        }

        .ev-site-nav .ev-dropdown-panel,
        .ev-site-nav #mobile-menu > ul {
            background: rgba(255, 255, 255, 0.98) !important;
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 22px 44px -28px rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(16px);
        }

        .ev-site-nav .ev-dropdown-panel a:hover,
        .ev-site-nav #mobile-menu a:hover,
        .ev-site-nav #mobile-menu button:hover {
            background: #eff6ff !important;
            color: #2563eb !important;
        }
    </style>

    <!-- Google Tag Manager -->
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','GTM-NZF599BJ');</script>
        <!-- End Google Tag Manager -->

    <!-- Google tag (gtag.js) -->
    @if(config('analytics.tracking_id'))
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('analytics.tracking_id') }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '{{ config('analytics.tracking_id') }}');
        </script>
    @endif
</head>

<body class="flex flex-col min-h-screen bg-ev-white text-ev-gray-800 @yield('body_class')">
    <nav class="ev-site-nav fixed top-0 z-50 w-full bg-opacity-90 bg-ev-blue-800">
        <div class="container px-4 mx-auto">
            <div class="flex items-center justify-between py-4">
                <a href="{{ route('home') }}" class="ev-nav-brand flex items-center text-xl font-bold text-ev-white">
                    <img src="{{ asset('images/logo-files/logo.png') }}" alt="Sagansa EV Logo" class="ev-nav-logo h-8 w-8 object-contain rounded-lg mr-2">
                    Sagansa - EV
                </a>

                <button class="lg:hidden text-ev-white" onclick="toggleMenu()">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16m-7 6h7"></path>
                    </svg>
                </button>

                <ul class="items-center justify-center flex-1 hidden space-x-12 lg:flex">
                    <li class="relative group">
                        <button onclick="toggleMapDesktopDropdown()"
                            class="flex items-center transition duration-300 text-ev-white hover:text-ev-green-400 {{ request()->routeIs(['pln-map', 'map']) ? 'nav-active font-bold text-ev-green-400' : '' }}">
                            Maps
                            <svg class="w-4 h-4 ml-1 transition-transform duration-200 transform" id="map-desktop-arrow"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="map-desktop-dropdown"
                            class="ev-dropdown-panel absolute hidden w-48 py-2 mt-2 rounded-md shadow-xl bg-ev-blue-800">
                            <a href="{{ route('pln-map') }}"
                                class="block px-4 py-2 text-sm text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('pln-map') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">
                                PLN Map
                            </a>
                            <a href="{{ route('map') }}"
                                class="block px-4 py-2 text-sm text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('map') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">
                                Community Map
                            </a>
                        </div>
                    </li>
                    {{-- <li class="relative group">
                        <button onclick="toggleDesktopDropdown()"
                            class="flex items-center transition duration-300 text-ev-white hover:text-ev-green-400 {{ request()->routeIs(['chargers', 'pln-chargers']) ? 'font-bold text-ev-green-400' : '' }}">
                            Chargers
                            <svg class="w-4 h-4 ml-1 transition-transform duration-200 transform" id="desktop-arrow"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="desktop-dropdown"
                            class="absolute hidden w-48 py-2 mt-2 rounded-md shadow-xl bg-ev-blue-800">
                            <a href="{{ route('chargers') }}"
                                class="block px-4 py-2 text-sm text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('chargers') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">
                                Community Chargers
                            </a>
                            <a href="{{ route('pln-chargers') }}"
                                class="block px-4 py-2 text-sm text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('pln-chargers') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">
                                PLN Chargers
                            </a>
                        </div>
                    </li> --}}
                    <li><a href="{{ route('providers') }}"
                            class="transition duration-300 text-ev-white hover:text-ev-green-400 {{ request()->routeIs('providers') ? 'font-bold text-ev-green-400' : '' }}">Providers</a>
                    </li>
                    <li><a href="{{ route('products') }}"
                            class="transition duration-300 text-ev-white hover:text-ev-green-400 {{ request()->routeIs('products') ? 'font-bold text-ev-green-400' : '' }}">Products</a>
                    </li>
                    <li><a href="{{ route('training') }}"
                            class="transition duration-300 text-ev-white hover:text-ev-green-400 {{ request()->routeIs('training') ? 'font-bold text-ev-green-400' : '' }}">Seminar EV</a>
                    </li>
                    <li><a href="{{ route('youtube.index') }}"
                            class="transition duration-300 text-ev-white hover:text-ev-green-400 {{ request()->routeIs('youtube.index') ? 'font-bold text-ev-green-400' : '' }}">Insights & Stories</a>
                    </li>
                    <li><a href="{{ route('contact') }}"
                            class="transition duration-300 text-ev-white hover:text-ev-green-400 {{ request()->routeIs('contact') ? 'font-bold text-ev-green-400' : '' }}">Contact
                            Us</a></li>
                </ul>

                <ul class="items-center hidden space-x-4 lg:flex">
                    <li>
                        <button type="button" 
                                onclick="window.openEvAppModal ? window.openEvAppModal() : null" 
                                class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-slate-900 border border-slate-700 hover:border-emerald-400 text-white font-bold text-xs shadow-md shadow-emerald-500/10 hover:shadow-emerald-500/25 transition-all duration-200">
                            <svg viewBox="0 0 512 512" class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg">
                                <path fill="#00D3FF" d="M32.5 17.5c-4.2 4.4-6.5 11.2-6.5 20.3v436.4c0 9.1 2.3 15.9 6.5 20.3l1.2 1.1 244.7-244.7v-5.8L33.7 16.4l-1.2 1.1z"/>
                                <path fill="#00F076" d="M359.8 322.2l-81.4-81.4v-5.8l81.4-81.4 1.8 1 96.5 54.8c27.5 15.6 27.5 41.2 0 56.9l-96.5 54.9-1.8 1z"/>
                                <path fill="#FF3A44" d="M361.6 321.2L278.4 238 32.5 483.9c9.1 9.6 24 10.7 41.1 1.1l288-163.8z"/>
                                <path fill="#FFC400" d="M361.6 190.8L73.6 27c-17.1-9.6-32-8.5-41.1 1.1L278.4 274l83.2-83.2z"/>
                            </svg>
                            <span>Download App</span>
                        </button>
                    </li>
                    @auth
                        <li><a href="{{ route('filament.admin.auth.login') }}"
                                class="transition duration-300 text-ev-white hover:text-ev-green-400 {{ request()->routeIs('filament.admin.auth.login') ? 'font-bold text-ev-green-400' : '' }}">Apps</a>
                        </li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit"
                                    class="transition duration-300 text-ev-white hover:text-ev-green-400">Logout</button>
                            </form>
                        </li>
                    @else
                        <li><a href="{{ route('filament.admin.auth.login') }}"
                                class="transition duration-300 text-ev-white hover:text-ev-green-400 {{ request()->routeIs('login') ? 'font-bold text-ev-green-400' : '' }}">Login</a>
                        </li>
                    @endauth
                </ul>
            </div>
        </div>
        <div id="mobile-menu" class="hidden lg:hidden">
            <ul class="py-2 bg-ev-blue-800">
                <li class="relative">
                    <button onclick="toggleMapMobileDropdown()"
                        class="flex items-center justify-between w-full px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs(['pln-map', 'map']) ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">
                        Maps
                        <svg class="w-4 h-4 ml-1 transition-transform duration-200 transform" id="map-mobile-arrow"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7">
                            </path>
                        </svg>
                    </button>
                    <div id="map-mobile-dropdown" class="hidden px-4 bg-ev-blue-900">
                        <a href="{{ route('pln-map') }}"
                            class="block px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('pln-map') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">
                            PLN Map
                        </a>
                        <a href="{{ route('map') }}"
                            class="block px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('map') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">
                            Community Map
                        </a>
                    </div>
                </li>
                {{-- <li class="relative">
                    <button onclick="toggleMobileDropdown()"
                        class="flex items-center justify-between w-full px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs(['chargers', 'pln-chargers']) ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">
                        Chargers
                        <svg class="w-4 h-4 ml-1 transition-transform duration-200 transform" id="mobile-arrow"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7">
                            </path>
                        </svg>
                    </button>
                    <div id="mobile-dropdown" class="hidden px-4 bg-ev-blue-900">
                        <a href="{{ route('pln-chargers') }}"
                            class="block px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('pln-chargers') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">
                            PLN Chargers
                        </a>
                        <a href="{{ route('chargers') }}"
                            class="block px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('chargers') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">
                            Community Chargers
                        </a>
                    </div>
                </li> --}}
                <li><a href="{{ route('providers') }}"
                        class="block px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('providers') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">Providers</a>
                </li>
                <li><a href="{{ route('products') }}"
                        class="block px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('products') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">Products</a>
                </li>
                <li><a href="{{ route('training') }}"
                        class="block px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('training') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">Seminar EV</a>
                </li>
                <li><a href="{{ route('youtube.index') }}"
                        class="block px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('youtube.index') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">Insights & Stories</a>
                </li>
                <li><a href="{{ route('contact') }}"
                        class="block px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('contact') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">Contact
                        Us</a>
                </li>

                <li>
                    <button type="button" 
                            onclick="window.openEvAppModal ? window.openEvAppModal() : null" 
                            class="flex items-center justify-between w-full px-4 py-2.5 font-bold text-emerald-400 bg-slate-900 hover:bg-slate-800 border-y border-slate-700">
                        <span class="flex items-center gap-2">
                            <svg viewBox="0 0 512 512" class="w-4 h-4" xmlns="http://www.w3.org/2000/svg">
                                <path fill="#00D3FF" d="M32.5 17.5c-4.2 4.4-6.5 11.2-6.5 20.3v436.4c0 9.1 2.3 15.9 6.5 20.3l1.2 1.1 244.7-244.7v-5.8L33.7 16.4l-1.2 1.1z"/>
                                <path fill="#00F076" d="M359.8 322.2l-81.4-81.4v-5.8l81.4-81.4 1.8 1 96.5 54.8c27.5 15.6 27.5 41.2 0 56.9l-96.5 54.9-1.8 1z"/>
                                <path fill="#FF3A44" d="M361.6 321.2L278.4 238 32.5 483.9c9.1 9.6 24 10.7 41.1 1.1l288-163.8z"/>
                                <path fill="#FFC400" d="M361.6 190.8L73.6 27c-17.1-9.6-32-8.5-41.1 1.1L278.4 274l83.2-83.2z"/>
                            </svg>
                            Download Mobile App
                        </span>
                        <span class="text-[10px] bg-emerald-500/20 text-emerald-300 px-2 py-0.5 rounded border border-emerald-500/30">Baru</span>
                    </button>
                </li>

                <!-- Garis pemisah -->
                <li class="my-2 border-t border-ev-blue-700"></li>

                @auth
                    <li><a href="{{ route('filament.admin.auth.login') }}"
                            class="block px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('filament.admin.auth.login') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">Apps</a>
                    </li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                class="block w-full px-4 py-2 text-left text-ev-white hover:bg-ev-blue-700">Logout</button>
                        </form>
                    </li>
                @else
                    <li><a href="{{ route('filament.admin.auth.login') }}"
                            class="block px-4 py-2 text-ev-white hover:bg-ev-blue-700 {{ request()->routeIs('login') ? 'font-bold bg-ev-blue-700 text-ev-green-400' : '' }}">Login</a>
                    </li>
                @endauth
            </ul>
        </div>
    </nav>

    @php
        $contentClasses = trim($__env->yieldContent('content_classes', 'flex-grow pt-16 md:pt-16'));
    @endphp

    <div class="{{ $contentClasses }}">
        @yield('content')
    </div>

    @stack('scripts')

    <script>
        function toggleMenu() {
            const mobileMenu = document.getElementById('mobile-menu');
            mobileMenu.classList.toggle('hidden');
        }

        function toggleDesktopDropdown() {
            const dropdown = document.getElementById('desktop-dropdown');
            const arrow = document.getElementById('desktop-arrow');
            dropdown.classList.toggle('hidden');
            arrow.classList.toggle('rotate-180');
        }

        function toggleMobileDropdown() {
            const dropdown = document.getElementById('mobile-dropdown');
            const arrow = document.getElementById('mobile-arrow');
            dropdown.classList.toggle('hidden');
            arrow.classList.toggle('rotate-180');
        }

        function toggleMapDesktopDropdown() {
            const dropdown = document.getElementById('map-desktop-dropdown');
            const arrow = document.getElementById('map-desktop-arrow');
            dropdown.classList.toggle('hidden');
            arrow.classList.toggle('rotate-180');
        }

        function toggleMapMobileDropdown() {
            const dropdown = document.getElementById('map-mobile-dropdown');
            const arrow = document.getElementById('map-mobile-arrow');
            dropdown.classList.toggle('hidden');
            arrow.classList.toggle('rotate-180');
        }

        // Update click outside handler
        document.addEventListener('click', function(event) {
            const desktopDropdown = document.getElementById('desktop-dropdown');
            const mobileDropdown = document.getElementById('mobile-dropdown');
            const mapDesktopDropdown = document.getElementById('map-desktop-dropdown');
            const mapMobileDropdown = document.getElementById('map-mobile-dropdown');

            const desktopButton = event.target.closest('.group button');
            const mobileButton = event.target.closest('.relative button');

            if (!desktopButton && !desktopDropdown.contains(event.target)) {
                desktopDropdown.classList.add('hidden');
                document.getElementById('desktop-arrow').classList.remove('rotate-180');
            }

            if (!mobileButton && !mobileDropdown.contains(event.target)) {
                mobileDropdown.classList.add('hidden');
                document.getElementById('mobile-arrow').classList.remove('rotate-180');
            }

            if (!desktopButton && !mapDesktopDropdown.contains(event.target)) {
                mapDesktopDropdown.classList.add('hidden');
                document.getElementById('map-desktop-arrow').classList.remove('rotate-180');
            }

            if (!mobileButton && !mapMobileDropdown.contains(event.target)) {
                mapMobileDropdown.classList.add('hidden');
                document.getElementById('map-mobile-arrow').classList.remove('rotate-180');
            }
        });
    </script>
    <x-app-download-modal />
</body>

</html>
