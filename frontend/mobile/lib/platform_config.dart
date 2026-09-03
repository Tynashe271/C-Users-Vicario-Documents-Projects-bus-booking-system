abstract final class PlatformConfig {
  static const apiUrl = String.fromEnvironment(
    'API_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  static const graphqlUrl = String.fromEnvironment(
    'GRAPHQL_URL',
    defaultValue: 'http://10.0.2.2:8000/graphql',
  );

  static const reverbUrl = String.fromEnvironment(
    'REVERB_URL',
    defaultValue: 'ws://10.0.2.2:8080',
  );
}
