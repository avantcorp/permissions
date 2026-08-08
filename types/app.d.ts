// Shared page props the host application is expected to provide.
import '@inertiajs/core';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            auth: {
                permissions: string[];
                roles: string[];
            };
        };
    }
}
