# Быстрое исправление v0.5.13

## Проблема
У вас удалены все карточки, но в `flashcards_progress` остались записи → это osиротевшие записи (orphaned).

## Быстрое решение (5 минут)

### 1. Проверить сколько осиротевших записей
```sql
SELECT COUNT(*) FROM mdl_flashcards_progress p
LEFT JOIN mdl_flashcards_cards c ON c.deckid=p.deckid AND c.cardid=p.cardid
WHERE c.id IS NULL;
```

### 2. Удалить осиротевшие записи
```sql
DELETE p FROM mdl_flashcards_progress p
LEFT JOIN mdl_flashcards_cards c ON c.deckid=p.deckid AND c.cardid=p.cardid
WHERE c.id IS NULL;
```

### 3. Проверить (должно быть 0)
```sql
SELECT COUNT(*) FROM mdl_flashcards_progress p
LEFT JOIN mdl_flashcards_cards c ON c.deckid=p.deckid AND c.cardid=p.cardid
WHERE c.id IS NULL;
```

### 4. Обновить код
```bash
# Уже исправлено в ajax.php строка 584
# Очистить кэши
php admin/cli/purge_caches.php
```

## Готово!
Теперь при удалении карточки автоматически удаляется ВСЕ прогресс (не только ваш).

---

**Подробности**: см. `FIX_ORPHANED_PROGRESS.md`
