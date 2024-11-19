<?php
$full_path = "{$_SERVER['REQUEST_SCHEME']}://{$_SERVER['HTTP_HOST']}/bitrix/js/main/jquery/jquery-3.6.0.min.js";
?>
<script src="<?=$full_path?>"></script>

<!-- Форма фильтров -->
<div id="filter-form">

    <!-- Поставщик -->
    <fieldset>
        <legend>Поставщик:</legend>
        <select name="filter[SUPPLIER_ID]" id="supplier-select">
            <option value="">Все</option>
            <!-- Опции будут загружены динамически -->
        </select>
    </fieldset>
    
    <!-- Раздел -->
    <fieldset>
        <legend>Раздел:</legend>
        <label>
            <input type="radio" name="filter[TARGET_SECTION_ID]" value="all" checked>
            все
        </label>
        <label>
            <input type="radio" name="filter[TARGET_SECTION_ID]" value="filled">
            заполнен
        </label>
        <label>
            <input type="radio" name="filter[TARGET_SECTION_ID]" value="empty">
            пустой
        </label>
    </fieldset>

    <!-- Связанные -->
    <fieldset>
        <legend>Связанные:</legend>
        <label>
            <input type="radio" name="filter[CHAIN_TOGEZER]" value="all" checked>
            все
        </label>
        <label>
            <input type="radio" name="filter[CHAIN_TOGEZER]" value="filled">
            заполнен
        </label>
        <label>
            <input type="radio" name="filter[CHAIN_TOGEZER]" value="empty">
            пустой
        </label>
    </fieldset>

    <!-- Цвет -->
    <fieldset>
        <legend>Цвет:</legend>
        <label>
            <input type="radio" name="filter[COLOR_VALUE_ID]" value="all" checked>
            все
        </label>
        <label>
            <input type="radio" name="filter[COLOR_VALUE_ID]" value="filled">
            заполнен
        </label>
        <label>
            <input type="radio" name="filter[COLOR_VALUE_ID]" value="empty">
            пустой
        </label>
    </fieldset>

    <!-- Размер -->
    <fieldset>
        <legend>Размер:</legend>
        <label>
            <input type="radio" name="filter[SIZE_VALUE_ID]" value="all" checked>
            все
        </label>
        <label>
            <input type="radio" name="filter[SIZE_VALUE_ID]" value="filled">
            заполнен
        </label>
        <label>
            <input type="radio" name="filter[SIZE_VALUE_ID]" value="empty">
            пустой
        </label>
    </fieldset>

    <!-- Существует в каталоге -->
    <fieldset>
        <legend>Существует в каталоге:</legend>
        <label>
            <input type="radio" name="filter[EXISTS_IN_CATALOG]" value="all" checked>
            все
        </label>
        <label>
            <input type="radio" name="filter[EXISTS_IN_CATALOG]" value="yes">
            да
        </label>
        <label>
            <input type="radio" name="filter[EXISTS_IN_CATALOG]" value="no">
            нет
        </label>
    </fieldset>

    <button type="button" id="apply-filter-button">Применить фильтр</button>
</div>

<!-- Таблица данных -->
<div id="data-table">
    <!-- Данные будут загружены динамически -->
</div>

<script>
    $(document).ready(function () {
        // Объявляем переменные для сортировки
        var sortField = 'ELEMENT_NAME';
        var sortOrder = 'ASC';

        function loadData(page = 1) {
            var formData = $('#filter-form').find(':input').serializeArray();
            formData.push({ name: 'page', value: page });
            formData.push({ name: 'sort_field', value: sortField });
            formData.push({ name: 'sort_order', value: sortOrder });

            $.ajax({
                url: '/local/modules/pragma.importmodule/data_table_content.php',
                type: 'GET',
                data: formData,
                dataType: 'html',
                success: function (data) {
                    $('#data-table').html(data);
                }
            });
        }

        // Загрузка списка поставщиков для фильтра
        function loadSuppliers() {
            $.ajax({
                url: '/local/modules/pragma.importmodule/data_table_content.php',
                type: 'GET',
                data: { action: 'get_suppliers' },
                dataType: 'json',
                success: function (data) {
                    var select = $('#supplier-select');
                    data.forEach(function (supplier) {
                        select.append('<option value="' + supplier.ID + '">' + supplier.NAME + ' (' + supplier.ID + ')</option>');
                    });
                }
            });
        }

        $('#apply-filter-button').on('click', function (e) {
            e.preventDefault();
            loadData(1); // Сбрасываем на первую страницу при применении фильтра
        });

        // Обработчик сортировки
        $(document).on('click', '.sort-link', function (e) {
            e.preventDefault();
            sortField = $(this).data('field');
            sortOrder = $(this).data('order');
            loadData(1); // Сбрасываем на первую страницу при изменении сортировки
        });

        // Обработчик пагинации
        $(document).on('click', '.pagination a', function (e) {
            e.preventDefault();
            var page = $(this).data('page');
            loadData(page);
        });

        // Начальная загрузка данных
        loadSuppliers();
        loadData();
    });
</script>