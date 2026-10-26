<?php

/**
 * Every source package: its slug (omnischolar/<slug>, github.com/glitchr-omnisense/omnischolar-<slug>),
 * its tests' namespace and its factory class.
 */
return [
    'openalex' => ['Omnischolar\\OpenAlex\\Tests\\', 'Omnischolar\\OpenAlex\\OpenAlexSourceFactory'],
    'hal' => ['Omnischolar\\Hal\\Tests\\', 'Omnischolar\\Hal\\HalSourceFactory'],
    'crossref' => ['Omnischolar\\Crossref\\Tests\\', 'Omnischolar\\Crossref\\CrossrefSourceFactory'],
    'orcid' => ['Omnischolar\\Orcid\\Tests\\', 'Omnischolar\\Orcid\\OrcidSourceFactory'],
    'openlibrary' => ['Omnischolar\\OpenLibrary\\Tests\\', 'Omnischolar\\OpenLibrary\\OpenLibrarySourceFactory'],
    'arxiv' => ['Omnischolar\\Arxiv\\Tests\\', 'Omnischolar\\Arxiv\\ArxivSourceFactory'],
    'inspire' => ['Omnischolar\\Inspire\\Tests\\', 'Omnischolar\\Inspire\\InspireSourceFactory'],
];
