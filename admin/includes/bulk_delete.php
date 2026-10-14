<?php

function adminPostedIds(): array
{
    $raw = $_POST['ids'] ?? [];
    if (!is_array($raw)) {
        return [];
    }

    $ids = [];
    foreach ($raw as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function adminApplyBulkDelete(callable $deleteOne): array
{
    $ids = adminPostedIds();
    if (!$ids) {
        return ['empty' => true, 'deleted' => 0, 'failed' => 0];
    }

    $deleted = 0;
    $failed = 0;
    foreach ($ids as $id) {
        try {
            if ($deleteOne($id)) {
                $deleted++;
            } else {
                $failed++;
            }
        } catch (Throwable $e) {
            $failed++;
        }
    }

    return ['empty' => false, 'deleted' => $deleted, 'failed' => $failed];
}

function adminBulkDeleteMessages(array $result): array
{
    if (!empty($result['empty'])) {
        return ['', 'Выберите записи для удаления'];
    }

    $message = '';
    $error = '';
    if (!empty($result['deleted'])) {
        $message = 'Удалено: ' . (int)$result['deleted'];
    }
    if (!empty($result['failed'])) {
        $error = 'Не удалось удалить: ' . (int)$result['failed'];
    } elseif ($message === '') {
        $error = 'Не удалось удалить выбранные записи';
    }

    return [$message, $error];
}

function adminBulkDeleteForm(string $confirm): void
{
    echo '<form method="post" id="bulkDeleteForm" class="bulk-delete-form" data-bulk-root="bulkDeleteRoot" data-confirm="'
        . htmlspecialchars($confirm, ENT_QUOTES) . '"></form>';
}

function adminBulkDeleteButton(): void
{
    echo '<button type="submit" form="bulkDeleteForm" name="action" value="delete_selected" class="btn btn-sm btn-outline bulk-delete-btn" style="color: var(--danger);" disabled>'
        . '<i class="bi bi-trash"></i><span>Удалить выбранные</span></button>';
}

function adminBulkSelectAll(bool $enabled, string $title = 'Выбрать все'): void
{
    echo '<th class="bulk-col"><input type="checkbox" class="form-check-input bulk-select-all" title="'
        . htmlspecialchars($title, ENT_QUOTES) . '"' . ($enabled ? '' : ' disabled') . '></th>';
}

function adminBulkRowCheck(int $id): void
{
    if ($id <= 0) {
        echo '<td class="bulk-col"></td>';
        return;
    }

    echo '<td class="bulk-col"><input type="checkbox" class="form-check-input bulk-row-check" form="bulkDeleteForm" name="ids[]" value="'
        . $id . '"></td>';
}
