<?php

namespace Database\Seeders\Support;

/**
 * Original short texts written for this project's demonstration library.
 * They are placeholders for trying the reader in three languages and may be
 * reused freely (CC0). The Russian and Hebrew versions are translations made
 * for the demo and are not polished literary translations.
 */
class DemoTexts
{
    private static function paragraphs(array $paragraphs): string
    {
        return implode('', array_map(fn (string $p) => '<p>'.$p.'</p>', $paragraphs));
    }

    /** @return list<array{title:string, html:string}> */
    public static function lighthouseEnglish(bool $revised = false): array
    {
        $note = '<p>The keeper wrote the hours of the tide on the wall in chalk'
            .'<a epub:type="noteref" role="doc-noteref" href="#n1">*</a>, and rubbed them out each spring.</p>'
            .'<aside epub:type="footnote" role="doc-footnote" id="n1" class="note"><p>* Chalk was chosen because the sea air lifted paint from the stone within a year.</p></aside>';

        return [
            ['title' => 'The Lamp', 'html' => self::paragraphs([
                'The lighthouse at the end of the breakwater had one lamp and one keeper, and neither of them slept at night. When the sun went down behind the hills the keeper climbed the ninety-one steps, trimmed the wick, and set the lens turning.',
                'From the gallery the harbour looked like a handful of spilled coins. Fishing boats came home in a line, each with a small light of its own, and the keeper counted them the way other people count sheep.',
                $revised
                    ? 'In this revised edition the keeper’s notes have been compared against the harbour log, and three dates have been corrected.'
                    : 'On clear nights the beam reached the far islands. On foggy nights it reached nothing at all, and that was when the bell was rung instead.',
                'Nobody had asked the keeper to write anything down. The almanac began as a list of lamp oil, and grew, year by year, into a book about weather, birds, and the patience of water.',
            ]).$note],
            ['title' => 'Weather', 'html' => self::paragraphs([
                'There are four winds on this coast and the keeper named them after aunts. The north wind was Aunt Vera, who arrived without warning and stayed too long. The south wind was kinder and smelled of the orchards inland.',
                'A red sky in the morning meant the boats would stay tied up. A ring around the moon meant rain within two days, and the keeper was wrong about that only twice in eleven years.',
                'The barometer on the wall was a gift from a ship’s captain. Its needle shivered before every storm, and the keeper trusted it more than the newspaper, which came by boat and was always a day old.',
                'The harbour had two names. Sailors from the east called it <span lang="he" dir="rtl">נמל האור</span>, the harbour of light, and the traders from the north wrote <span lang="ru">Гавань Света</span> on their charts. The keeper used both, depending on who was listening.',
            ])],
            ['title' => 'Visitors', 'html' => self::paragraphs([
                'In summer children rowed out to the breakwater and asked to see the lamp. The keeper let them climb the stairs two at a time and showed them how the lens bent one small flame into a road of light across the sea.',
                'In winter the only visitors were gulls and, once, a seal that slept on the lowest step for a week. The keeper recorded it in the almanac between the price of oil and the date of the first frost.',
                'When the keeper grew old, a younger keeper came, and the almanac was left on the shelf beside the lamp. It is still there. People who visit the harbour may read it, and that is how you come to be reading it now.',
                'More about how this demonstration library works is on the <a href="https://example.org/about-the-demo">project page</a>.',
            ])],
        ];
    }

    /** @return list<array{title:string, html:string}> */
    public static function lighthouseRussian(): array
    {
        $note = '<p>Смотритель записывал часы прилива мелом на стене'
            .'<a epub:type="noteref" role="doc-noteref" href="#n1">*</a> и каждую весну стирал их.</p>'
            .'<aside epub:type="footnote" role="doc-footnote" id="n1" class="note"><p>* Мел выбрали потому, что морской воздух за год снимал краску с камня.</p></aside>';

        return [
            ['title' => 'Лампа', 'html' => self::paragraphs([
                'На маяке в конце волнореза была одна лампа и один смотритель, и ни он, ни она не спали по ночам. Когда солнце садилось за холмы, смотритель поднимался на девяносто одну ступень, подрезал фитиль и запускал линзу.',
                'С галереи гавань казалась горстью рассыпанных монет. Рыбацкие лодки возвращались домой цепочкой, у каждой был свой огонёк, и смотритель считал их так, как другие считают овец.',
                'В ясные ночи луч доставал до дальних островов. В туманные ночи он не доставал ни до чего, и тогда вместо света звонили в колокол.',
                'Никто не просил смотрителя что-нибудь записывать. Альманах начался со списка лампового масла и год за годом превратился в книгу о погоде, птицах и терпении воды.',
            ]).$note],
            ['title' => 'Погода', 'html' => self::paragraphs([
                'На этом берегу четыре ветра, и смотритель назвал их в честь тётушек. Северный ветер был тётей Верой: она являлась без предупреждения и гостила слишком долго. Южный ветер был добрее и пах садами из глубины страны.',
                'Красное небо поутру значило, что лодки останутся на привязи. Кольцо вокруг луны значило дождь в ближайшие два дня, и за одиннадцать лет смотритель ошибся в этом только дважды.',
                'Барометр на стене подарил капитан одного корабля. Его стрелка вздрагивала перед каждой бурей, и смотритель верил ему больше, чем газете, которую привозили на лодке с опозданием на день.',
                'У гавани было два имени. Моряки с востока называли её <span lang="he" dir="rtl">נמל האור</span>, гаванью света, а на английских картах значилось <span lang="en">Harbour of Light</span>. Смотритель пользовался обоими, смотря по тому, кто слушал.',
            ])],
            ['title' => 'Гости', 'html' => self::paragraphs([
                'Летом дети приплывали на вёслах к волнорезу и просили показать лампу. Смотритель позволял им взбегать по лестнице через ступеньку и показывал, как линза превращает маленькое пламя в дорогу света через море.',
                'Зимой гостями бывали только чайки и однажды тюлень, который неделю спал на нижней ступени. Смотритель записал это в альманах между ценой на масло и датой первого заморозка.',
                'Когда смотритель состарился, пришёл смотритель помоложе, а альманах остался на полке рядом с лампой. Он и теперь там. Всякий, кто приезжает в гавань, может его прочесть, — так он и попал к вам.',
            ])],
        ];
    }

    /** @return list<array{title:string, html:string}> */
    public static function lighthouseHebrew(): array
    {
        $note = '<p>השומר רשם את שעות הגאות בגיר על הקיר'
            .'<a epub:type="noteref" role="doc-noteref" href="#n1">*</a> ומחק אותן בכל אביב.</p>'
            .'<aside epub:type="footnote" role="doc-footnote" id="n1" class="note"><p>* הגיר נבחר מפני שאוויר הים קילף את הצבע מן האבן בתוך שנה.</p></aside>';

        return [
            ['title' => 'הפנס', 'html' => self::paragraphs([
                'במגדלור שבקצה שובר הגלים היו פנס אחד ושומר אחד, ושניהם לא ישנו בלילות. כששקעה השמש מאחורי הגבעות טיפס השומר תשעים ואחת מדרגות, קיצץ את הפתיל והניע את העדשה.',
                'מן המרפסת נראה הנמל כחופן מטבעות שהתפזרו. סירות הדיג שבו הביתה בשורה, לכל אחת אור קטן משלה, והשומר ספר אותן כפי שאחרים סופרים כבשים.',
                'בלילות בהירים הגיעה האלומה עד האיים הרחוקים. בלילות ערפל לא הגיעה לשום מקום, ואז צלצלו בפעמון במקומה.',
                'איש לא ביקש מן השומר לכתוב דבר. האלמנך התחיל כרשימה של שמן לפנס, וגדל, שנה אחר שנה, לספר על מזג האוויר, על ציפורים ועל סבלנותם של המים.',
            ]).$note],
            ['title' => 'מזג האוויר', 'html' => self::paragraphs([
                'בחוף הזה יש ארבע רוחות, והשומר קרא להן על שם דודות. רוח הצפון הייתה הדודה ורה, שהגיעה בלי התראה ונשארה זמן רב מדי. רוח הדרום הייתה נדיבה יותר והדיפה ריח של בוסתנים מפנים הארץ.',
                'שמיים אדומים בבוקר פירושם שהסירות יישארו קשורות. טבעת סביב הירח פירושה גשם בתוך יומיים, ובאחת־עשרה שנים טעה השומר בכך רק פעמיים.',
                'את הברומטר שעל הקיר נתן במתנה רב־חובל של אונייה. המחוג שלו רעד לפני כל סערה, והשומר בטח בו יותר מאשר בעיתון, שהגיע בסירה ותמיד היה בן יום.',
                'לנמל היו שני שמות. הסוחרים מן הצפון כתבו במפותיהם <span lang="ru" dir="ltr">Гавань Света</span>, ובמפות האנגליות נכתב <span lang="en" dir="ltr">Harbour of Light</span>. השומר השתמש בשניהם, לפי מי שהקשיב.',
            ])],
            ['title' => 'אורחים', 'html' => self::paragraphs([
                'בקיץ חתרו ילדים אל שובר הגלים וביקשו לראות את הפנס. השומר הניח להם לעלות במדרגות שתיים־שתיים והראה להם איך העדשה הופכת להבה קטנה לדרך של אור על פני הים.',
                'בחורף היו האורחים היחידים שחפים, ופעם אחת כלב ים שישן שבוע על המדרגה התחתונה. השומר רשם זאת באלמנך בין מחיר השמן לתאריך הכפור הראשון.',
                'כשהזדקן השומר בא שומר צעיר ממנו, והאלמנך נשאר על המדף ליד הפנס. הוא שם עד היום. כל מי שמבקר בנמל רשאי לקרוא בו, וכך הגיע גם אליכם.',
            ])],
        ];
    }

    /** Pointed (vocalized) Hebrew, to show vowel marks rendering correctly. */
    public static function morningHebrew(): array
    {
        return [
            ['title' => 'בֹּקֶר', 'html' => self::paragraphs([
                'בֹּקֶר טוֹב, עוֹלָם.',
                'הַשֶּׁמֶשׁ עוֹלָה מֵעַל הַיָּם.',
                'הַסֵּפֶר פָּתוּחַ עַל הַשֻּׁלְחָן.',
                'יֶלֶד קוֹרֵא סִפּוּר.',
            ])],
            ['title' => 'עֶרֶב', 'html' => self::paragraphs([
                'הַיּוֹם הוֹלֵךְ וְהָעֶרֶב בָּא.',
                'אוֹר קָטָן דּוֹלֵק בַּחַלּוֹן.',
                'שָׁלוֹם לְכָל הַקּוֹרְאִים.',
            ])],
        ];
    }

    public static function morningEnglish(): array
    {
        return [
            ['title' => 'Morning', 'html' => self::paragraphs([
                'Good morning, world.',
                'The sun rises over the sea.',
                'The book lies open on the table.',
                'A child reads a story.',
            ])],
            ['title' => 'Evening', 'html' => self::paragraphs([
                'The day goes and the evening comes.',
                'A small light burns in the window.',
                'Peace to all who read.',
            ])],
        ];
    }

    /** @return list<list<string>> */
    public static function readingRoomPdfPages(): array
    {
        return [
            ['A Short Guide to the Reading Room', '', 'This three-page PDF is a demonstration file.', 'It has a text layer, so search and selection work.', '',
                'Page 1: Arriving', 'The reading room is open to everyone. No card is needed.', 'Choose a table near a window if you like daylight.'],
            ['Finding a book', '', 'Books are shelved by language and then by author.', 'Every edition of a work is kept together, so you can',
                'compare one translation with another side by side.', 'Ask at the desk about the catalogue and its filters.'],
            ['Leaving', '', 'Return books to the trolley, not to the shelf.', 'Your place is remembered: a lighthouse bookmark is',
                'left in every book you open. Good night.'],
        ];
    }
}
