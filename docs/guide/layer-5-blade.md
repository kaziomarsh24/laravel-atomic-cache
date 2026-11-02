# Layer 5: Blade Fragment Caching (`@atomicCache`)

Cache expensive HTML blocks, navigation bars, and widgets directly inside your Blade views.

---

### 5.1 Usage & Tagging

Wrap HTML blocks using `@atomicCache` and close with `@endAtomicCache`:

```blade
{{-- Caches rendered HTML for 3600 seconds, tagged with 'categories' and 'sub_categories' --}}
@atomicCache('sidebar_categories', 3600, ['categories', 'sub_categories'])
    <div class="sidebar">
        @foreach(\App\Models\Category::with('subcategories')->get() as $category)
            <h3>{{ $category->name }}</h3>
            @foreach($category->subcategories as $sub)
                <p>{{ $sub->title }}</p>
            @endforeach
        @endforeach
    </div>
@endAtomicCache
```

---

### 5.2 Critical Rules & What Breaks Blade Caching

::: danger Never Cache Forms or CSRF Tokens
CSRF tokens are unique to each user session. If you cache a form containing `@csrf`, the first user's CSRF token is stored. When subsequent users submit the form, they will receive a **`419 Page Expired`** error!
:::

::: danger Never Cache User-Specific Data Without User Scoping
If you cache a block containing `@atomicCache('user_header')` with the user's name or cart count, User A's personal information will be cached and shown to User B (a major data leak).

*Solution:* Append the user ID to the key:
```blade
@atomicCache('user_header_' . auth()->id(), 3600)
```
:::

* **Multi-table dependencies:** If your cached Blade fragment renders data from multiple models/tables (such as categories and subcategories), specify all relevant tags: `@atomicCache('sidebar', 3600, ['categories', 'sub_categories'])`. If you only pass `'categories'`, updates to subcategories will not invalidate the cached HTML.
* **Never cache session flash messages or `@error` directives:** `session('status')` or `@error('email')` cached inside a fragment will either never display or remain permanently visible to all subsequent visitors.
* **Unclosed `@atomicCache` blocks:** Omitting `@endAtomicCache` leaves an active PHP output buffer open, which can break page rendering.
