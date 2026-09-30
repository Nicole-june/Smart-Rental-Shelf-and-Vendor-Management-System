document.addEventListener('DOMContentLoaded', function () {
    var toggleBtn = document.getElementById('sidebarToggle');
    var sidebar = document.getElementById('sidebar');

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });
    }
});


function enhanceSearchableSelects() {
    document.querySelectorAll('select.searchable').forEach(function (select) {
        if (select.dataset.enhanced) {
            return;
        }
        select.dataset.enhanced = '1';

        var wrapper = document.createElement('div');
        wrapper.className = 'searchable-select-wrapper';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.classList.add('searchable-select-hidden');

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'searchable-select-input';
        input.setAttribute('autocomplete', 'off');
        input.placeholder = select.getAttribute('data-placeholder') || 'Search...';

        var dropdown = document.createElement('div');
        dropdown.className = 'searchable-select-dropdown';

        wrapper.appendChild(input);
        wrapper.appendChild(dropdown);

        function buildOptionsList() {
            dropdown.innerHTML = '';
            Array.prototype.forEach.call(select.options, function (option) {
                if (option.value === '') {
                    return; // skip the "Select..." placeholder from the list itself
                }
                var item = document.createElement('div');
                item.className = 'searchable-select-option';
                item.textContent = option.textContent;
                if (option.selected) {
                    item.classList.add('selected');
                }
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // keep input focused so click registers before blur
                    select.value = option.value;
                    input.value = option.textContent;
                    dropdown.classList.remove('open');
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                });
                dropdown.appendChild(item);
            });
        }

        function filterOptions() {
            var term = input.value.toLowerCase();
            Array.prototype.forEach.call(dropdown.children, function (item) {
                item.style.display = item.textContent.toLowerCase().indexOf(term) !== -1 ? '' : 'none';
            });
        }

        function syncInputToSelection() {
            var opt = select.options[select.selectedIndex];
            input.value = (opt && opt.value !== '') ? opt.textContent : '';
        }

        buildOptionsList();
        syncInputToSelection();

        input.addEventListener('focus', function () {
            dropdown.classList.add('open');
            filterOptions();
        });
        input.addEventListener('input', function () {
            dropdown.classList.add('open');
            filterOptions();
        });
        document.addEventListener('click', function (e) {
            if (!wrapper.contains(e.target)) {
                dropdown.classList.remove('open');
                syncInputToSelection();
            }
        });

        // Stay in sync if something else changes the select's value.
        select.addEventListener('change', function () {
            syncInputToSelection();
            buildOptionsList();
        });
    });
}

document.addEventListener('DOMContentLoaded', enhanceSearchableSelects);


function togglePasswordVisibility(inputId, button) {
    var input = document.getElementById(inputId);
    if (!input) {
        return;
    }
    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = 'Hide';
    } else {
        input.type = 'password';
        button.textContent = 'Show';
    }
}