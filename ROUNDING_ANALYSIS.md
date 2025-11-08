# Анализ проблемы округления сумм при отображении

## Текущая проблема

Пользователь видит:
```
К оплате: 33.75 TMT
Оплачен: 0 TMT
Итого: 33.75 TMT
```

При настройках:
- Округление по сумме: **Включено**
- Количество знаков после запятой: **0**
- Направление округления: **standard** (или другое)

Ожидается: округленные значения (например, `34 TMT` вместо `33.75 TMT`)

---

## Анализ реализации на бэкенде

### Настройки округления в Company

**Поля в таблице `companies`:**
- `rounding_decimals` - количество знаков после запятой (0-5)
- `rounding_enabled` - включено/выключено округление
- `rounding_direction` - направление округления:
  - `standard` - стандартное округление (Math.round)
  - `up` - округление вверх (Math.ceil)
  - `down` - округление вниз (Math.floor)
  - `custom` - кастомное с порогом
- `rounding_custom_threshold` - порог для custom (0-1)

### RoundingService на бэкенде

**Файл:** `back/app/Services/RoundingService.php`

**Методы:**
- `roundForCompany($companyId, $value)` - округляет суммы (amounts)
- `roundQuantityForCompany($companyId, $value)` - округляет количество (quantity)

**Логика:**
1. Если `rounding_enabled = false` → обрезает (truncate) без округления
2. Если `rounding_enabled = true` → округляет согласно `rounding_direction`:
   - `standard` → `round($value, $decimals)`
   - `up` → `ceil($value * 10^decimals) / 10^decimals`
   - `down` → `floor($value * 10^decimals) / 10^decimals`
   - `custom` → округляет вверх если дробная часть >= threshold, иначе вниз

### Где применяется округление на бэке

**OrdersRepository:**
- При создании заказа (строки 447-451):
  ```php
  $price = $roundingService->roundForCompany($companyId, (float) $price);
  $discount_calculated = $roundingService->roundForCompany($companyId, (float) $discount_calculated);
  $total_price = $roundingService->roundForCompany($companyId, (float) $total_price);
  ```
- При обновлении заказа (строки 657-661): аналогично

**Вывод:** На бэке округление ПРАВИЛЬНО применяется при сохранении в базу!

---

## Анализ реализации на фронтенде

### Где отображается сумма

**Файл:** `front/src/views/pages/orders/OrderCreatePage.vue` (строки 152-154)

```javascript
formatCurrency(totalPrice, currencySymbol, null, true)
formatCurrency(paidTotalAmount, currencySymbol, null, true)
formatCurrency(totalPrice - paidTotalAmount, currencySymbol, null, true)
```

**Параметры:**
- `decimals = null` → берется из `store.getters.roundingDecimals` (0 знаков)
- `showDecimals = true` → показывать десятичные знаки

### Computed свойства

**Файл:** `front/src/views/pages/orders/OrderCreatePage.vue` (строки 294-311)

```javascript
subtotal() {
  return this.products.reduce((sum, p) => {
    const price = Number(p.price) || 0;
    const qty = Number(p.quantity) || 0;
    return sum + price * qty;  // НЕ округляется!
  }, 0);
}

totalPrice() {
  return this.subtotal - this.discountAmount;  // НЕ округляется!
}
```

**ПРОБЛЕМА:** Значения вычисляются на фронте без округления!

### Функция formatNumber

**Файл:** `front/src/utils/numberUtils.js` (строки 16-90)

**Текущая логика:**
1. Берет `decimals` из настроек компании (0)
2. НЕ округляет значение, только обрезает строку:
   ```javascript
   decimalPart = decimalPart.substring(0, decimals); // "75" → "" при decimals=0
   ```
3. Если `decimals=0` и `showDecimals=true`:
   - `decimalPart` становится пустой строкой после substring(0, 0)
   - Проверка `decimalPart.length > 0` → false
   - Должно вернуть только `integerPart` ("33")

**НО:** Почему-то показывается `33.75` вместо `33` или `34`

### Функция roundValue на фронте

**Файл:** `front/src/utils/numberUtils.js` (строки 286-296)

**Что делает:**
- Реально округляет число согласно настройкам компании:
  - `roundingDecimals` (0 знаков)
  - `roundingEnabled` (включено/выключено)
  - `roundingDirection` (standard/up/down/custom)
  - `roundingCustomThreshold` (порог для custom)

**Где используется:**
- При сохранении цен товаров (строки 534, 542 в OrderCreatePage.vue)

---

## ПРАВИЛЬНАЯ ЛОГИКА (из ответов пользователя)

### ✅ Правила работы с округлением:

1. **Округление должно быть на бэке, а не на фронте при отображении**
   - На фронте НЕ нужно округлять при отображении
   - На фронте нужно передавать на бэк уже округленные значения
   - Бэк должен записывать и вести расчеты правильно

2. **Есть 2 варианта округления:**
   - Для **сумм** (amounts) - использует `rounding_decimals`, `rounding_enabled`, `rounding_direction`, `rounding_custom_threshold`
   - Для **количества** (quantity) - использует `rounding_quantity_decimals`, `rounding_quantity_enabled`, `rounding_quantity_direction`, `rounding_quantity_custom_threshold`

3. **Проблема:** При отображении показывается `33.75 TMT` вместо `33 TMT` (при `decimals=0`)
   - `formatNumber` должен обрезать строку, но не округлять
   - При `decimals=0` и `showDecimals=true` должно показывать только целую часть (обрезать, не округлять)

---

## ВОПРОСЫ ДЛЯ УТОЧНЕНИЯ

### ❓ Вопрос 1: Что передавать на бэк при сохранении?

**Текущая ситуация:**
- При сохранении заказа на фронте передаются:
  - `products[].price` - округляется через `roundValue()` только для новых заказов (строка 386)
  - `products[].quantity` - НЕ округляется
  - `discount` - НЕ округляется
  - `total_price` - НЕ передается (бэк пересчитывает)

**Вопрос:** Что нужно передавать на бэк уже округленным?

**Варианты:**
- **A)** Только цены товаров (`price`) - уже так и делается
- **B)** Цены товаров (`price`) + количество (`quantity`) - нужно округлять через `roundQuantityValue()`
- **C)** Цены товаров + количество + discount + total_price - все должно быть округлено перед отправкой

**Ваш ответ:** ?

---

### ❓ Вопрос 2: Что показывать при отображении?

**Ситуация:** `decimals = 0`, `showDecimals = true`, значение `33.75`

**Вопрос:** Что должно показываться?

**Варианты:**
- **A)** `33` - обрезать до целого (не округлять)
- **B)** `33.75` - показывать как есть (неправильно, но так сейчас)
- **C)** `34` - округлить до целого (но пользователь сказал не округлять при отображении)

**Ваш ответ:** ?

---

### ❓ Вопрос 3: Исправление formatNumber

**Проблема:** При `decimals=0` и `showDecimals=true` показывается `33.75` вместо `33`

**Вопрос:** Нужно ли исправить `formatNumber`, чтобы он правильно обрезал строку при `decimals=0`?

**Текущая логика:**
```javascript
decimalPart = decimalPart.substring(0, decimals); // "75" → "" при decimals=0
if (decimalPart.length > 0) {
  result = `${integerPart}.${decimalPart}`; // Не выполняется, т.к. длина 0
} else {
  result = integerPart; // Должно вернуть "33"
}
```

**Но почему показывается 33.75?** Может быть проблема в другом месте?

**Ваш ответ:** Нужно ли исправить `formatNumber`? ?

---

## Решение

### Вариант 1: Округлять computed свойства на фронте

Изменить computed свойства в `OrderCreatePage.vue`:

```javascript
totalPrice() {
  const raw = this.subtotal - this.discountAmount;
  return roundValue(raw);  // Округляем перед возвратом
}
```

### Вариант 2: Округлять в formatCurrency перед форматированием

Изменить вызовы:

```javascript
formatCurrency(roundValue(totalPrice), currencySymbol, null, false)
```

### Вариант 3: Исправить formatNumber чтобы округлять при decimals=0

Изменить `formatNumber` чтобы округлять значение когда `decimals=0`:

```javascript
// Округляем перед форматированием
if (decimals >= 0) {
  const factor = Math.pow(10, decimals);
  num = Math.round(num * factor) / factor;
}
```

---

## Следующие шаги

1. ✅ Понял, что округление должно быть на бэке (уже реализовано)
2. ✅ Понял варианты округления (standard/up/down/custom)
3. ❓ Нужно понять: почему показывается неокругленное значение, если оно уже округлено на бэке?
4. ❓ Нужно понять: должно ли округление применяться на фронте для нового заказа (еще не сохраненного)?

