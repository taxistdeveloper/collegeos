# Microsoft Word Online в проекте

## Что это даёт

**Настоящий Word в браузере** от Microsoft — тот же интерфейс, что на office.com.

## Два уровня

### 1. Просмотр (проще)

- Вкладка «Word Online» → документ в iframe
- **Нужно:** сайт доступен по **HTTPS** из интернета (не `http://localhost`)
- В `app/config/wordonline.php` укажите:

```php
'public_base_url' => 'https://ваш-домен.kz/ecollege',
```

### 2. Редактирование (сложнее, для IT-отдела)

Нужно всё из списка:

| Требование | Пояснение |
|------------|-----------|
| **HTTPS** | Обязательно публичный домен |
| **WOPI-host** | REST API на PHP (CheckFileInfo, GetFile, PutFile, Lock) |
| **Office Online Server** | Сервер Microsoft на Windows **или** программа CSPP + Microsoft 365 |
| **Лицензии M365** | У преподавателей для редактирования |

Документация Microsoft:  
https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/online/

Пример WOPI на .NET: https://github.com/OfficeDev/PnP-WOPI

Для колледжа на MAMP **рекомендуем:**

- **Word на компьютере** — скачать/загрузить .docx (основной способ в проекте)

Word Online имеет смысл, если у вас уже есть **Microsoft 365 для всего колледжа** и системный администратор готов поднять WOPI.

## Сравнение

| | Word на ПК | Word Online |
|--|------------|-------------|
| Docker | Нет | Нет* |
| Точный Word | Да | Да |
| localhost | Да | Нет |
| Сложность | Низкая | Высокая |

\* Нужен Office Online Server или облако Microsoft.
