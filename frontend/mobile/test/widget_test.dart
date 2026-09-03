import 'package:flutter_test/flutter_test.dart';
import 'package:passenger_mobile/main.dart';

void main() {
  testWidgets('shows the platform connection summary', (tester) async {
    await tester.pumpWidget(const PassengerApp());

    expect(find.text('Find and book your next bus trip.'), findsOneWidget);
    expect(find.text('REST API'), findsOneWidget);
    expect(find.text('GraphQL'), findsOneWidget);
    expect(find.text('Live updates'), findsOneWidget);
  });
}
