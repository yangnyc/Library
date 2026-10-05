<?php

// Long-form page text. Each page is a title, an optional lead and sections of
// [heading, [paragraphs…]]. ":name" and ":kindle_url" are filled in by the view.

return [
    'about' => [
        'title' => 'About :name',
        'lead' => ':name is a public reading library: books you can read in a web browser or download, without creating an account.',
        'sections' => [
            ['What is here', [
                'Every book is published as one or more editions. An edition is a particular text — the original, or one specific translation — in one language. A work can have several editions, including more than one in the same language.',
                'Only editions that are in the public domain, openly licensed, or published with the permission of the rights holder are made available. Each edition page shows its source, its rights status and the attribution.',
            ]],
            ['Languages', [
                'The interface, the catalog details and the books are independent of each other: you can use the site in one language while reading a book in another, including right-to-left books.',
            ]],
            ['Accounts are optional', [
                'Reading and downloading never need an account. Without one, your reading position, bookmarks and settings are kept in your browser. An optional account syncs them between your devices.',
            ]],
        ],
    ],

    'accessibility' => [
        'title' => 'Accessibility',
        'lead' => 'We aim for the website and the reader controls to meet WCAG 2.2 level AA.',
        'sections' => [
            ['What we do', [
                'Pages use headings, landmarks and labelled controls; everything can be reached and operated with a keyboard, and keyboard focus is always visible.',
                'Text can be enlarged with browser zoom to 200% and beyond without losing content. In the EPUB reader you can also change the font, text size, line spacing, margins and colours, including a high-contrast theme.',
                'Animations are kept minimal and are switched off when your system asks for reduced motion.',
                'Each page and each book declares its language and text direction so that screen readers choose the right voice.',
            ]],
            ['The website and the books are different things', [
                'An accessible reader does not make every book accessible. EPUB editions are generally readable with a screen reader, but their internal structure (headings, image descriptions) depends on how the source publication was made.',
                'PDF editions keep the page layout of print. A PDF that is a scan without a text layer is a set of images: it cannot be searched, selected or read by a screen reader, even though the viewer around it is accessible. Edition pages say so when we know it.',
            ]],
            ['Known limitations', [
                'Highlighting text in the reader requires a pointer or touch selection; notes can also be added to a bookmark from the keyboard.',
                'We have tested with automated tools and with keyboard-only use in current desktop browsers. Testing with assistive technology on physical phones and tablets is still limited.',
            ]],
            ['Tell us about a problem', [
                'If something does not work for you, please use the contact form and choose “Accessibility problem”. Tell us the page, your browser and any assistive technology you use.',
            ]],
        ],
    ],

    'privacy' => [
        'title' => 'Privacy',
        'lead' => 'The library is built to be used without identifying yourself.',
        'sections' => [
            ['Without an account', [
                'We do not ask who you are. Your interface language is remembered in a cookie. Your reading positions, bookmarks, notes and reader settings are stored only in your own browser and are not sent to us.',
                'Because that data lives in the browser, clearing site data, using private browsing, or the browser’s own storage clean-up can delete it.',
            ]],
            ['With an account', [
                'We store your name, email address and a hashed password, and the reading data you sync: positions, bookmarks, highlights, notes, favorites, reading lists and reader settings. Notes and highlights are private to your account.',
                'You can download everything we hold from your account page, and you can delete the account there. Deleting it removes the synced data from our database.',
            ]],
            ['Cookies', [
                'We use only cookies that the site needs: one for your session (signing in and form security) and ones for your language choices. There are no advertising or cross-site tracking cookies.',
            ]],
            ['Server logs and limits', [
                'Like any web server, ours records requests (address, time, page) in logs kept for a limited time for security and troubleshooting. Download and form requests are rate-limited per network address to prevent abuse.',
            ]],
            ['Books and outside content', [
                'Book content is shown in an isolated frame. Scripts in books are never run, and books cannot load images, fonts or trackers from other websites. Links inside a book that lead to other sites only open if you choose to follow them.',
            ]],
            ['Offline copies', [
                'If you save a book for offline reading, it is stored on your device by your browser. We keep no record of which books you saved.',
            ]],
        ],
    ],

    'help_reading' => [
        'title' => 'Reading in the browser',
        'lead' => 'Open an edition and choose “Read in browser”. Nothing needs to be installed.',
        'sections' => [
            ['EPUB books', [
                'Choose between continuous scrolling and pages. On wide screens the page mode can show two pages side by side.',
                'The settings panel changes the font, text size, line spacing, margins, reading width and theme (light, sepia, dark, high contrast). The book’s own styling is kept where it does not conflict with your choices.',
                'Use the contents list to move between chapters, and “Search in this book” to find text. Footnote links open in place where the book marks them as notes.',
                'Move with the on-screen buttons, the arrow keys, Page Up / Page Down, or by swiping. In a right-to-left book the directions follow the book.',
            ]],
            ['Your place in the book', [
                'Your position is saved automatically and restored the next time you open the same edition — also after you change the text size or rotate the screen, because it is tied to the text, not to a screen page.',
                'Screen pages depend on your font and window size, so the reader shows your position as a section and a percentage rather than as a page number. Print page numbers appear only when the book itself contains them.',
                'Positions belong to one edition. A translation is a different text, so your place is not carried over between editions.',
            ]],
            ['Bookmarks, highlights and notes', [
                'Add a bookmark at any time. Select text to highlight it or attach a private note. Without an account these are kept in this browser only.',
            ]],
            ['PDF books', [
                'PDFs are shown page by page as printed. You can zoom, fit the page to the width, go to a page number and use full screen. Text search and selection work when the PDF has a text layer.',
                'A PDF cannot be reflowed: the text size and line breaks cannot be changed the way they can in an EPUB. If an EPUB of the same edition exists, it is the better choice on a phone.',
                'A scanned PDF without a text layer cannot be searched or selected. You can still download it and open it in another viewer.',
            ]],
            ['When something is not supported', [
                'Fixed-layout EPUBs and some interactive features are shown in a simplified way. When the reader cannot show a book well it tells you, and offers the download where that is permitted.',
            ]],
        ],
    ],

    'help_downloads' => [
        'title' => 'Downloading books',
        'lead' => 'Downloads are free and do not need an account.',
        'sections' => [
            ['Formats', [
                'EPUB is the adjustable ebook format: text reflows to your screen and you can change its size. It works in most reading apps, such as Apple Books, Google Play Books, Kobo, Thorium Reader and many others.',
                'PDF keeps the fixed page layout of a printed book. It is offered only for editions that have an approved PDF.',
                'Plain text or HTML files are offered for a few editions where they were supplied.',
            ]],
            ['Why a format may be missing', [
                'A download button appears only for files that exist and that we are authorized to distribute. Some editions may be read here but not downloaded, or the other way round; the edition page explains which and why.',
            ]],
            ['Using a downloaded file', [
                'Open the file with a reading app on your device. On a phone or tablet, the browser usually offers to open the EPUB in an installed reading app after the download finishes.',
                'Files are not copy-protected. Please respect the license shown on the edition page.',
            ]],
        ],
    ],

    'help_kindle' => [
        'title' => 'Reading on Kindle',
        'lead' => 'Kindle devices and apps receive books through Amazon’s own Send to Kindle service. This site gives you the EPUB file; you send it with Amazon.',
        'sections' => [
            ['Steps', [
                '1. On the edition page, download the EPUB file to your computer, phone or tablet.',
                '2. Open Amazon’s Send to Kindle page (:kindle_url) and sign in there with your own Amazon account.',
                '3. Choose the EPUB file you downloaded and send it. Amazon converts it and delivers it to your Kindle library.',
                '4. Open the book on your Kindle device or in the Kindle app once it appears.',
            ]],
            ['Good to know', [
                'We never ask for your Amazon details, and this site cannot send a book to your Kindle for you. There is no button here that delivers automatically.',
                'Amazon decides which files it accepts and how it converts them. After delivery, check that the book looks right on your device — especially right-to-left books such as Hebrew, books with vowel marks, and books with complex layout.',
                'Send to Kindle accepting EPUB does not mean a Kindle opens EPUB files copied to it over a USB cable. Use Send to Kindle rather than copying the file directly.',
                'Reading progress on your Kindle is not connected to your reading position on this website.',
                'Only editions with an EPUB can be sent this way. PDF files can also be sent with Send to Kindle but keep their fixed pages.',
            ]],
        ],
    ],

    'help_offline' => [
        'title' => 'Installing and offline reading',
        'lead' => 'You can add the library to your home screen and save individual books to read without a connection.',
        'sections' => [
            ['Saving a book for offline reading', [
                'Open the book in the reader and choose “Save for offline reading”. Only the book you choose is saved; the library never downloads books on its own.',
                'The reader shows the progress of saving and lets you cancel. A book counts as available offline only when every part of it has been saved.',
                'Saved books are listed on the Offline shelf, where you can open or remove them and see how much storage is used.',
                'Offline saving is offered only for editions whose rights allow it.',
            ]],
            ['Limits you should know about', [
                'The browser controls this storage. It may remove saved books when the device runs low on space or when the site has not been used for some time. If that happens, save the book again while online.',
                'If saving stops because storage is full, the incomplete copy is removed and you are told. Free some space or remove other saved books and retry.',
                'Removing an edition from the library does not remove copies already saved on devices.',
            ]],
            ['Installing the app', [
                'In Chrome or Edge on Android and on desktop, use “Install app” or “Add to Home screen” in the browser menu.',
                'On iPhone and iPad, open the site in Safari, tap Share, then “Add to Home Screen”.',
                'Installation and background behaviour differ between browsers and devices. Everything also works as an ordinary website without installing.',
            ]],
            ['Accounts and offline reading', [
                'Reading positions made offline are stored on the device and synced to your account the next time you are online and signed in.',
                'When you sign out, this account’s synced reading data is removed from the browser. Books saved for offline reading are public content and stay until you remove them.',
            ]],
        ],
    ],
];
