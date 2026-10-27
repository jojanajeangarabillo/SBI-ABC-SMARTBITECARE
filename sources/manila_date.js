/*
 * Today's date in the clinic's time zone (Asia/Manila), as YYYY-MM-DD.
 * The server (PHP date() and MySQL CURDATE()) uses Asia/Manila; a browser
 * whose clock runs in another zone (or UTC) would otherwise default date
 * fields to yesterday before 8:00 am Manila time.
 */
(function (global) {
    function sbcManilaToday(now) {
        var date = now instanceof Date ? now : new Date();
        try {
            // en-CA formats as YYYY-MM-DD.
            return new Intl.DateTimeFormat('en-CA', {
                timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'
            }).format(date);
        } catch (e) {
            // Manila has no daylight saving time: UTC+8 all year.
            return new Date(date.getTime() + 8 * 3600 * 1000).toISOString().slice(0, 10);
        }
    }
    global.sbcManilaToday = sbcManilaToday;
})(window);
