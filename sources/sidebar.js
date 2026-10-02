document.addEventListener('DOMContentLoaded', function () {

    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');

    if (!sidebar || !toggle) {
        console.error('Sidebar or sidebar toggle was not found.');
        return;
    }

    const icon = toggle.querySelector('i');


    /* =====================================================
       UPDATE ARROW
       ===================================================== */

    function updateArrow() {

        const collapsed = sidebar.classList.contains('collapsed');

        if (collapsed) {

            /*
             * Sidebar is collapsed.
             * Arrow points RIGHT.
             * User can click it to expand.
             */
            icon.className = 'bi bi-chevron-right';

            toggle.setAttribute(
                'aria-label',
                'Expand sidebar'
            );

            toggle.setAttribute(
                'aria-expanded',
                'false'
            );

        } else {

            /*
             * Sidebar is expanded.
             * Arrow points LEFT.
             * User can click it to collapse.
             */
            icon.className = 'bi bi-chevron-left';

            toggle.setAttribute(
                'aria-label',
                'Collapse sidebar'
            );

            toggle.setAttribute(
                'aria-expanded',
                'true'
            );
        }
    }


    /* =====================================================
       TOGGLE
       ===================================================== */

    toggle.addEventListener('click', function () {

        sidebar.classList.toggle('collapsed');

        document.body.classList.toggle(
            'sidebar-collapsed',
            sidebar.classList.contains('collapsed')
        );

        updateArrow();
    });


    /* =====================================================
       INITIAL STATE
       ===================================================== */

    updateArrow();

});