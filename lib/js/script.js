document.addEventListener('DOMContentLoaded', function () {
    var autoModeCheckbox = document.getElementById('AUTO_MODE');
    var delayTimeRow = document.getElementById('delay_time_row');
    var agentIntervalRow = document.getElementById('agent_interval_row');
    var agentNextExecRow = document.getElementById('agent_next_exec_row');

    function toggleModeSettings() {
        if (autoModeCheckbox.checked) {
            delayTimeRow.style.display = '';
            agentIntervalRow.style.display = 'none';
            agentNextExecRow.style.display = 'none';
        } else {
            delayTimeRow.style.display = 'none';
            agentIntervalRow.style.display = '';
            agentNextExecRow.style.display = '';
        }
    }

    autoModeCheckbox.addEventListener('change', toggleModeSettings);
    toggleModeSettings();

    // Обновление списка разделов при изменении инфоблока каталога
    document.getElementById('IBLOCK_ID_CATALOG').addEventListener('change', function () {
        const iblockId = this.value;
        if (iblockId) {
            updateSectionOptions(iblockId);
        } else {
            // Если инфоблок не выбран, очищаем все списки разделов
            const sectionSelects = document.querySelectorAll('.section-select');
            sectionSelects.forEach(select => {
                select.innerHTML = '<option value="">' + select.options[0].text + '</option>';
            });
        }
    });

    // Проверяем наличие опций в select'ах
    const sectionSelects = document.querySelectorAll('.section-select');
    sectionSelects.forEach(select => {
        if (select.options.length <= 1) { // <= 1, так как есть пустая опция
            const iblockId = document.getElementById('IBLOCK_ID_CATALOG').value;
            if (iblockId) {
                updateSectionOptions(iblockId, select);
            }
        }
    });
});

function addMapping() {
    const template = document.getElementById('section-mapping-template').content.cloneNode(true);
    const container = document.getElementById('section_mappings_container');
    const index = container.children.length;

    // Обновляем индексы
    template.querySelector('select').name = `SECTION_MAPPINGS[${index}][SECTION_ID]`;
    template.querySelectorAll('input').forEach(input => {
        input.name = `SECTION_MAPPINGS[${index}][PROPERTIES][]`;
    });

    container.appendChild(template);

    // Загружаем опции для нового select
    updateSectionOptions(document.getElementById('IBLOCK_ID_CATALOG').value, template.querySelector('select'));
}

function removeMapping(button) {
    button.closest('.section-mapping').remove();
}

function addProperty(button) {
    var container = button.closest('.section-mapping').querySelector('.properties-container');
    var newProperty = document.createElement('div');
    newProperty.className = 'property';
    newProperty.innerHTML = `
    <input type="text" name="">
    <button type="button" onclick="removeProperty(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_PROPERTY") ?></button>
`;
    container.appendChild(newProperty);

    // Обновляем имя поля
    var sectionSelect = button.closest('.section-mapping').querySelector('select');
    var sectionIndex = sectionSelect.name.match(/\[(.*?)\]/)[1];
    newProperty.querySelector('input').name = `SECTION_MAPPINGS[${sectionIndex}][PROPERTIES][]`;
}

function removeProperty(button) {
    button.closest('.property').remove();
}

// Функция для обновления списка разделов
function updateSectionOptions(iblockId, selectElement = null, selectedSectionId = null) {
    if (!iblockId) return;

    fetch(`/local/modules/pragma.importmodule/lib/ajax.php?IBLOCK_ID=${iblockId}`)
        .then(response => response.text())
        .then(html => {
            const selectsToUpdate = selectElement ? [selectElement] : document.querySelectorAll('.section-select');
            selectsToUpdate.forEach(select => {
                // Сохраняем текущее выбранное значение
                const currentValue = select.value;
                select.innerHTML = html;

                // Восстанавливаем выбранное значение, если оно существует в новом списке
                if (currentValue && select.querySelector(`option[value="${currentValue}"]`)) {
                    select.value = currentValue;
                } else if (selectedSectionId) {
                    const option = select.querySelector(`option[value="${selectedSectionId}"]`);
                    if (option) {
                        option.selected = true;
                    }
                }
            });
        })
        .catch(error => console.error('Error fetching section options:', error));
}

document.getElementById('resetButton').addEventListener('click', function (event) {
    event.preventDefault();

    // Загружаем значения по умолчанию
    const defaultOptions = <?= CUtil :: PhpToJSObject($pragma_import_module_default_option) ?>;

    // Устанавливаем значения по умолчанию для полей формы
    document.getElementById('IBLOCK_ID_IMPORT').value = defaultOptions.IBLOCK_ID_IMPORT;
    document.getElementById('IBLOCK_ID_CATALOG').value = defaultOptions.IBLOCK_ID_CATALOG;
    document.getElementById('AUTO_MODE').checked = defaultOptions.AUTO_MODE === 'Y';
    document.getElementById('DELAY_TIME').value = defaultOptions.DELAY_TIME;
    document.getElementById('AGENT_INTERVAL').value = <?= $agentInterval ?>; // Используем актуальные данные агента
    document.getElementById('AGENT_NEXT_EXEC').value = '<?= date('Y - m - d\TH:i', strtotime($agentNextExec)) ?>'; // Используем актуальные данные агента

    // Очищаем все сопоставления разделов
    const container = document.getElementById('section_mappings_container');
    container.innerHTML = '';

    // Добавляем одно пустое сопоставление
    addMapping();

    // Очищаем все списки разделов
    const sectionSelects = document.querySelectorAll('.section-select');
    sectionSelects.forEach(select => {
        select.innerHTML = '<option value="">' + select.options[0].text + '</option>';
    });

    // Если есть значение по умолчанию для IBLOCK_ID_CATALOG, загружаем разделы
    if (defaultOptions.IBLOCK_ID_CATALOG) {
        updateSectionOptions(defaultOptions.IBLOCK_ID_CATALOG);
    }

    // Вызываем функцию для отображения/скрытия полей в зависимости от режима запуска
    toggleModeSettings();
});