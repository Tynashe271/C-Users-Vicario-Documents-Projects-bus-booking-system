import 'package:flutter/material.dart';
import 'package:passenger_mobile/platform_config.dart';

void main() {
  runApp(const PassengerApp());
}

class PassengerApp extends StatelessWidget {
  const PassengerApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'Bus Booking',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xff059669)),
        useMaterial3: true,
      ),
      home: const PlatformHomePage(),
    );
  }
}

class PlatformHomePage extends StatelessWidget {
  const PlatformHomePage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Bus Booking')),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: const [
          Text(
            'Find and book your next bus trip.',
            style: TextStyle(fontSize: 32, fontWeight: FontWeight.bold),
          ),
          SizedBox(height: 24),
          _Endpoint(label: 'REST API', value: PlatformConfig.apiUrl),
          _Endpoint(label: 'GraphQL', value: PlatformConfig.graphqlUrl),
          _Endpoint(label: 'Live updates', value: PlatformConfig.reverbUrl),
        ],
      ),
    );
  }
}

class _Endpoint extends StatelessWidget {
  const _Endpoint({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(title: Text(label), subtitle: Text(value)),
    );
  }
}
