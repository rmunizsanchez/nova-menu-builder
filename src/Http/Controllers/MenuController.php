<?php

namespace OptimistDigital\MenuBuilder\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Laravel\Nova\Nova;
use OptimistDigital\MenuBuilder\Events\UpdateMenu;
use OptimistDigital\MenuBuilder\MenuBuilder;
use OptimistDigital\MenuBuilder\Models\Menu;
use OptimistDigital\MenuBuilder\Http\Requests\MenuItemFormRequest;

class MenuController extends Controller
{
    public function getMenus(Request $request)
    {
        return MenuBuilder::getMenuClass()::where('nodetype', 'menu')
            ->whereRaw('nlevel(path)=1')->get()->map(function ($menu) {
                return [
                'id' => $menu->id,
                'title' => "{$menu->name} ({$menu->slug})",
                'name' => $menu->name,
                'slug' => $menu->slug,
            ];
            });
    }

    public function copyMenuItemsToMenu(Request $request)
    {
        $data = $request->validate([
            'fromMenuId' => 'required',
            'toMenuId' => 'required',
            'fromLocale' => 'required',
            'toLocale' => 'required',
        ]);

        $fromMenuId = $data['fromMenuId'];
        $toMenuId = $data['toMenuId'];
        $fromLocale = $data['fromLocale'];
        $toLocale = $data['toLocale'];

        $fromMenu = Menu::find($fromMenuId);
        $toMenu = Menu::find($toMenuId);

        if (!$fromMenu || !$toMenu) {
            return response()->json(['error' => 'menu_not_found'], 404);
        }

        $maxOrder = $fromMenu->rootMenuItems()->max('order');
        $i = 1;

        $recursivelyCloneMenuItems = function ($menuItems, $parentId = null) use ($toLocale, $toMenuId, $maxOrder, &$i, &$recursivelyCloneMenuItems) {
            foreach ($menuItems as $menuItem) {
                $newMenuItem = $menuItem->replicate();
                $newMenuItem->locale = $toLocale;
                $newMenuItem->menu_id = $toMenuId;
                $newMenuItem->parent_id = $parentId;
                $newMenuItem->order = $maxOrder + $i++;
                $newMenuItem->save();

                if ($menuItem->children->count() > 0) {
                    $recursivelyCloneMenuItems($menuItem->children, $newMenuItem->id);
                }
            }
        };

        // Clone all and add to toMenu
        $rootMenuItems = $fromMenu->rootMenuItems()->where('locale', $fromLocale)->get();
        $recursivelyCloneMenuItems($rootMenuItems);

        return response()->json(['success' => true], 200);
    }

    /**
     * Return root menu items for one menu.
     *
     * @param Illuminate\Http\Request $request
     * @param $menuId
     * @return Illuminate\Http\Response
     **/
    public function getMenuItems(Request $request, $menuId)
    {
        $locale = $request->get('locale');
        $menu = MenuBuilder::getMenuClass()::find($menuId);
        if (empty($menu)) {
            return response()->json(['menu' => 'menu_not_found'], 400);
        }
        if (empty($locale)) {
            return response()->json(['menu' => 'locale_required_but_missing'], 400);
        }

        $menuItems = $menu
            ->childs();

        return response()->json($menuItems, 200);
    }

    /**
     * Save menu items.
     *
     * @param Illuminate\Http\Request $request
     * @param $menuId
     * @return Illuminate\Http\Response
     **/
    public function saveMenuItems(Request $request, $menuId)
    {
        $items = $request->get('menuItems');

        $i = 1;
        foreach ($items as $item) {
            $this->saveMenuItemWithNewOrder($i, $item);
            $i++;
        }

        return response()->json(['success' => true], 200);
    }

    /**
     * Creates new MenuItem.
     *
     * @param OptimistDigital\MenuBuilder\Http\Requests\MenuItemFormRequest $request
     * @return Illuminate\Http\Response
     **/
    public function createMenuItem(MenuItemFormRequest $request)
    {
        $menuItemModel = MenuBuilder::getMenuItemClass();

        $data = $request->getValues();

        $model = new $menuItemModel;
        $model->assignParentValues($data['menu_id']);
        foreach ($data['values'] as $key => $value) {
            if (Str::contains($value, '{')) {
                $value = json_decode($value, true);
            }
            $model->{$key} = $value;
        }

        Nova::actionEvent()->forResourceCreate($request->user(), $model)->save();
        $model->save();

        return response()->json(['success' => true], 200);
    }

    /**
     * Returns the menu item as JSON.
     *
     * @param $menuItemId
     * @return Illuminate\Http\Response
     **/
    public function getMenuItem($menuItemId)
    {
        $menuItem = MenuBuilder::getMenuItemClass()::find($menuItemId);

        return isset($menuItem)
            ? response()->json($menuItem, 200)
            : response()->json(['error' => 'item_not_found'], 400);
    }

    /**
     * Updates a MenuItem.
     *
     * @param OptimistDigital\MenuBuilder\Http\Requests\MenuItemFormRequest $request
     * @param $menuItem
     * @return Illuminate\Http\Response
     **/
    public function updateMenuItem(MenuItemFormRequest $request, $menuItemId)
    {
        $menuItem = MenuBuilder::getMenuItemClass()::find($menuItemId);

        if (!isset($menuItem)) {
            return response()->json(['error' => 'menu_item_not_found'], 400);
        }
        $data = $request->getValues();

        foreach ($data['values'] as $key => $value) {
            if (Str::contains($value, '{')) {
                $value = json_decode($value, true);
            }
            $menuItem->{$key} = $value;
        }
        Nova::actionEvent()->forResourceUpdate($request->user(), $menuItem)->save();

        $menuItem->save();

        event(new UpdateMenu($menuItem->id));

        return response()->json(['success' => true], 200);
    }

    /**
     * Deletes a MenuItem.
     *
     * @param $menuItem
     * @return Illuminate\Http\Response
     **/
    public function deleteMenuItem(Request $request, $menuItemId)
    {
        $menuItem = MenuBuilder::getMenuItemClass()::findOrFail($menuItemId);
        $children = $menuItem->children()->get();
        $menuItem->children()->delete();
        $menuItem->delete();
        Nova::actionEvent()->forResourceDelete($request->user(), $children);
        Nova::actionEvent()->forResourceDelete($request->user(), collect([$menuItem]));
        return response()->json(['success' => true], 200);
    }

    /**
     * Get link types for locale.
     *
     * @param string $locale
     * @return Illuminate\Http\Response
     **/
    public function getMenuItemTypes(Request $request, $menuId)
    {
        $menu = MenuBuilder::getMenuClass()::find($menuId);
        if ($menu === null) {
            return response()->json(['error' => 'menu_not_found'], 404);
        }
        $locale = $request->get('locale');
        if ($locale === null) {
            return response()->json(['error' => 'locale_required'], 400);
        }

        $menuItemTypes = [];
        $menuItemTypesRaw = MenuBuilder::getMenuItemTypes();

        $formatAndAppendMenuItemType = function ($typeClass) use ($menu, &$menuItemTypes, $locale) {
            if (!class_exists($typeClass)) {
                return;
            }

            $data = [
                'name' => $typeClass::getName(),
                'type' => $typeClass::getType(),
                'default' => $typeClass::isDefault(),
                'fields' => MenuBuilder::getFieldsFromMenuItemTypeClass($typeClass, $menu) ?? [],
                'class' => $typeClass
            ];

            if (method_exists($typeClass, 'getOptions')) {
                $options = $typeClass::getOptions($locale) ?? [];
                $data['options'] = array_map(function ($value, $key) {
                    return ['id' => (string) $key, 'label' => $value];
                }, array_values($options), array_keys($options));
            }

            $menuItemTypes[] = $data;
        };

        foreach ($menuItemTypesRaw as $typeClass) {
            $formatAndAppendMenuItemType($typeClass);
        }

        $menu = MenuBuilder::getMenus()[$menu->slug] ?? null;
        if ($menu !== null) {
            $menuTypeClasses = $menu['menu_item_types'] ?? [];
            foreach ($menuTypeClasses as $menuTypeClass) {
                $formatAndAppendMenuItemType($menuTypeClass);
            }
        }

        return response()->json($menuItemTypes, 200);
    }

    /**
     * Duplicates a MenuItem.
     *
     * @param $menuItem
     * @return Illuminate\Http\Response
     **/
    public function duplicateMenuItem($menuItemId)
    {
        $menuItem = MenuBuilder::getMenuItemClass()::find($menuItemId);

        if (empty($menuItem)) {
            return response()->json(['error' => 'menu_item_not_found'], 400);
        }

        $this->shiftMenuItemsWithHigherOrder($menuItem);
        $this->recursivelyDuplicate($menuItem, $menuItem->parent, $menuItem->norder + 1);

        return response()->json(['success' => true], 200);
    }


    // ------------------------------
    // Helpers
    // ------------------------------

    /**
     * Increase order number of every menu item that has higher order number than ours by one
     *
     * @param $menuItem
     */
    private function shiftMenuItemsWithHigherOrder($menuItem)
    {
        $menuItems = MenuBuilder::getMenuItemClass()
            ::where('norder', '>', $menuItem->norder)
            ->where('path', $menuItem->parent)
            ->get();

        // Do individual updates to trigger observer(s)
        foreach ($menuItems as $menuItem) {
            $menuItem->norder = $menuItem->norder + 1;
            $menuItem->save();
        }
    }

    private function recursivelyOrderChildren($menuItem)
    {
        if (count($menuItem['children']) > 0) {
            foreach ($menuItem['children'] as $i => $child) {
                $this->saveMenuItemWithNewOrder($i + 1, $child, $menuItem['path']);
            }
        }
    }

    private function saveMenuItemWithNewOrder($orderNr, $menuItemData, $parentId = null)
    {
        $menuItem = MenuBuilder::getMenuItemClass()::find($menuItemData['id']);
        $menuItem->order = $orderNr;
        if ($parentId) {
            $menuItem->path = $parentId . '.' . $menuItem->id;
        }
        $menuItem->save();
        $this->recursivelyOrderChildren($menuItemData);
    }

    protected function recursivelyDuplicate($menuItem, $parentId = null, $order = null)
    {
        $data = $menuItem->replicate(['children', 'name', 'enabled', 'parent']);
        $parent = null;
        if ($parentId !== null) {
            if (is_string($parentId)) {
                $parent = MenuBuilder::getMenuItemClass()::query()->where('path', $parentId)
                    ->first();
            } else {
                $parent = $parentId;
            }
        }
        if ($order !== null) {
            $data->norder = $order;
        }

        // Save the long way instead of ::create() to trigger observer(s)
        if ($parent) {
            $data->assignParentValues($parent);
        }
        $data->save();

        $children = $menuItem->childs();

        foreach ($children as $child) {
            $this->recursivelyDuplicate($child, $data);
        }
    }
}
