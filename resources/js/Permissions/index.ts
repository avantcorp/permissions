import { usePage } from '@inertiajs/vue3';
import type { App, DirectiveBinding } from 'vue';
import Permissions from './Permissions';

export type PermissionType = {
    ability?: string;
    model: string | null;
    id?: number | string | null;
};

const parseFromBinding = (binding: { arg?: string; value?: string | PermissionType }): PermissionType | null => {
    const value = binding.value ?? null;

    if (value === null && typeof binding.arg === 'undefined') {
        return null;
    } else if (value === null && typeof binding.arg === 'string') {
        return { ability: binding.arg, id: null, model: null };
    } else if (typeof value === 'string' && typeof binding.arg === 'undefined') {
        return { ability: value, id: null, model: null };
    } else if ((typeof value === 'string' || typeof value === 'number') && typeof binding.arg === 'string' && binding.arg.includes('-')) {
        const [ability, model] = binding.arg.split('-');

        return { ability: ability, id: value, model: model };
    } else if (typeof value === 'string' && typeof binding.arg === 'string') {
        return { ability: binding.arg, id: null, model: value };
    }

    const permission = value as PermissionType;

    return {
        ability: (permission.ability ?? binding.arg) as string,
        id: permission.id,
        model: permission.model,
    };
};

export default {
    install(app: App) {
        app.directive('permission', {
            beforeMount: async function (el: HTMLElement, binding: DirectiveBinding) {
                const permission = parseFromBinding(binding);

                if (permission === null) {
                    return;
                }

                el.setAttribute('v-cloak', '');

                if (await Permissions.check(permission)) {
                    el.removeAttribute('v-cloak');
                } else {
                    el.parentElement?.removeChild(el);
                }
            },
        });
        app.directive<HTMLElement, string>('role', {
            async beforeMount(el: HTMLElement, binding: DirectiveBinding) {
                const role = binding.arg ?? binding.value;

                if (role === null) {
                    return;
                }

                el.setAttribute('v-cloak', '');

                if (!usePage().props.auth.roles.includes(role)) {
                    el.removeAttribute('v-cloak');
                } else {
                    el.parentElement?.removeChild(el);
                }
            },
        });
    },
};
