import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	personal: 'src/personal.js',
}, {
	inlineCSS: { relativeCSSInjection: true },
})
