import type { CodegenConfig } from '@graphql-codegen/cli'

// The schema is exported from the backend with `npm run schema` and committed,
// so codegen (and CI) never needs a running API.
const config: CodegenConfig = {
  overwrite: true,
  schema: './schema.graphql',
  documents: ['src/**/*.graphql'],
  generates: {
    './src/graphql/generated.ts': {
      plugins: ['typescript-operations', 'typed-document-node'],
      config: {
        scalars: { DateTime: 'string' },
        avoidOptionals: { field: true, inputValue: false },
        nonOptionalTypename: true,
        skipTypeNameForRoot: true,
        useTypeImports: true,
        enumsAsTypes: true,
      },
    },
  },
}

export default config
