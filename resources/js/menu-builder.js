import ToggleButton from 'vue-js-toggle-button';
import MenuIndexView from './views/MenuIndexView';
import MenuBuilder from './components/MenuBuilder';
import MenuBuilderField from './components/MenuBuilderField';

Nova.booting((app, store) => {
    Nova.inertia("menus", MenuIndexView.default);

    app.use(ToggleButton);

    app.component('menu-builder', MenuBuilder);
    app.component('form-menu-builder-field', MenuBuilderField);
    app.component('detail-menu-builder-field', MenuBuilderField);
});
